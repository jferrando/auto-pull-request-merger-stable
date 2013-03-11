#!/bin/sh

die () {
    echo >&2 "$@"
    echo "USAGE: $0 <github-pullRequestNumber> <user-story> ";
    exit 1
}


[ "$#" -eq 2 ] || die "2 parameters required, $# provided"
echo $1 | grep -E -q '^[0-9]+$' || die "Numeric argument required, $1 provided"

USER_STORY=$2
PULL_REQUEST=$1

git fetch origin
git branch $USER_STORY master
git checkout $USER_STORY
git merge "origin/pr/$PULL_REQUEST" -m "merged to test" 
