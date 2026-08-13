#!/usr/bin/env bash
#
# Checks finished PDFs with tools that did not write them.
#
# The test suite asserts that the bytes this library produces contain
# what it meant to put there. That is a different question from whether
# a reader can open the result, and the suite cannot answer the second
# one however many assertions it makes -- it shares every assumption the
# writer made. So: hand the files to somebody else and see what they
# say.
#
# Any output on stderr from any checker fails the run. That is stricter
# than "exit code non-zero" on purpose: poppler reports a form whose
# fields it cannot draw as a syntax error on stderr and still exits 0,
# which is exactly the class of problem worth catching (it is how the
# missing /ZaDb in /DR was found). The baseline is silence, so anything
# at all is a regression.
#
# Usage: tools/check-pdfs.sh [directory-of-pdfs]

set -uo pipefail

directory="${1:-examples/output}"

if [ ! -d "$directory" ]; then
    echo "No such directory: $directory" >&2
    exit 1
fi

shopt -s nullglob
pdfs=("$directory"/*.pdf)

if [ ${#pdfs[@]} -eq 0 ]; then
    echo "No PDFs in $directory -- nothing was checked." >&2
    exit 1
fi

checkers=()
command -v qpdf      >/dev/null && checkers+=("qpdf")
command -v gs        >/dev/null && checkers+=("ghostscript")
command -v pdftotext >/dev/null && checkers+=("poppler")

# A gate that quietly checks nothing is worse than no gate: it reports
# success forever and everyone stops thinking about it.
if [ ${#checkers[@]} -eq 0 ]; then
    echo "None of qpdf, ghostscript or poppler is installed -- refusing to report success." >&2
    exit 1
fi

failures=0

# The strict read-back, first, because it is the half that does not
# repair what it is given. Every external tool here rebuilds a broken
# cross-reference table silently: corrupt a startxref offset and gs and
# poppler both report nothing. check-pdfs.php refuses the same file.
# See its header for why the two halves are both needed.
if ! php "$(dirname "$0")/check-pdfs.php" "$directory"; then
    failures=$((failures + 1))
fi

echo
echo "Checking ${#pdfs[@]} PDFs in $directory with: ${checkers[*]}"

# Runs one checker, failing on a non-zero exit *or* any stderr output.
#
# Both conditions are needed and neither subsumes the other. Poppler
# reports a form it cannot draw on stderr and exits 0, so exit codes
# alone find nothing -- that is how the missing /ZaDb hid. Ghostscript is
# the other way round: it writes even "Unable to open the initial device"
# to *stdout*, and says so only in its exit code.
#
# Which is why both streams are kept and both are printed on a failure.
# An earlier version captured stderr and discarded stdout, so a
# ghostscript failure produced the word FAIL and not one word about why
# -- diagnosing it from a CI log was impossible, which is the one thing
# a gate has to be good at.
run_check() {
    local label="$1" file="$2"
    shift 2

    local errors output status
    errors=$(mktemp)
    output=$("$@" 2>"$errors")
    status=$?

    if [ $status -ne 0 ] || [ -s "$errors" ]; then
        echo "  FAIL  [$label] $(basename "$file") (exit $status)"
        [ -s "$errors" ] && sed 's/^/          /' "$errors"
        [ -n "$output" ] && echo "$output" | sed 's/^/          /'
        failures=$((failures + 1))
    fi

    rm -f "$errors"
}

for pdf in "${pdfs[@]}"; do
    for checker in "${checkers[@]}"; do
        case "$checker" in
            qpdf)
                # Structural: xref offsets, stream lengths, object
                # streams, the page tree. The one checker that reads a
                # PDF as a file format rather than as something to draw.
                run_check qpdf "$pdf" qpdf --check "$pdf"
                ;;
            ghostscript)
                # Renders every page and says so when it cannot. Catches
                # structural problems poppler tolerates in silence.
                #
                # No -o: the null device needs no output file, and asking
                # for /dev/null drags in whatever that build's -dSAFER
                # policy thinks of writing to a special file. One less
                # thing to differ between a workstation and a runner.
                run_check gs "$pdf" gs -dNOPAUSE -dBATCH -dQUIET -sDEVICE=nullpage "$pdf"
                ;;
            poppler)
                # A second opinion, and the one that reads forms and
                # fonts most strictly. pdfinfo covers the trailer and
                # catalog; pdftotext covers content streams and encoding.
                run_check pdfinfo "$pdf" pdfinfo "$pdf"
                run_check pdftotext "$pdf" pdftotext "$pdf" /dev/null
                ;;
        esac
    done
done

if [ $failures -ne 0 ]; then
    echo
    echo "$failures check(s) failed."
    exit 1
fi

echo "All clean."
