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
run_check() {
    local label="$1" file="$2"
    shift 2

    local output status
    output=$("$@" 2>&1 >/dev/null)
    status=$?

    if [ $status -ne 0 ] || [ -n "$output" ]; then
        echo "  FAIL  [$label] $(basename "$file")"
        [ -n "$output" ] && echo "$output" | sed 's/^/          /'
        failures=$((failures + 1))
    fi
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
                run_check gs "$pdf" gs -dNOPAUSE -dBATCH -dQUIET -sDEVICE=nullpage -o /dev/null "$pdf"
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
