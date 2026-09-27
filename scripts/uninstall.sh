#!/bin/bash

# Remove adobe script
rm -f "${MUNKIPATH}preflight.d/adobe"

# Remove adobe plist files
rm -f "${MUNKIPATH}preflight.d/cache/adobe.plist"
rm -f "${MUNKIPATH}preflight.d/cache/adobe_remote.plist"
