#! /bin/bash

usage () {
  echo "Usage: ./bump-release.sh {major|minor|patch}"
}

if [ $# -lt 1 ];
then
  usage
  exit 1
fi

VERSION_BUMP=$1
VALID_VERSION_BUMPS=("major" "minor" "patch")

if [[ ! " ${VALID_VERSION_BUMPS[@]} " =~ " ${VERSION_BUMP} " ]];
then
  echo "$1 is not a valid version bump."
  usage
  exit 1;
fi

CURRENT_VERSION=$(git tag -l | tail -n1)
NEW_VERSION=$(semver -i $VERSION_BUMP $CURRENT_VERSION)

echo "Current version: $CURRENT_VERSION"
echo "New version: $NEW_VERSION"

git tag v$NEW_VERSION
git push --tags
