# WordPress.org listing assets

Banners, icons and screenshots for the plugin's page in the WordPress.org
directory. **These are not part of the plugin.** wordpress.org serves them from
the `assets/` folder of the plugin's SVN repository, separately from the code, so
they must never be inside `tackquote.zip` — every byte that ships there is
downloaded by every user on every install and update, for images they will never
see in their admin.

`bin/build.sh` excludes this directory and then asserts the exclusion worked, so
a leak fails the build rather than quietly producing a fat zip.

## What is here

| File | Where it appears |
|---|---|
| `banner-1544x500.png`, `banner-772x250.png` | Header of the directory page (retina and standard) |
| `icon-128x128.png`, `icon-256x256.png` | Search results and the plugin card |
| `screenshot-1.png` … `screenshot-4.png` | The Screenshots tab, captioned **in numeric order** by the `== Screenshots ==` list in `readme.txt` |
| `mark-bw.png`, `preview-on-white.png` | Source marks kept for regenerating the above |

## The one rule that is easy to break

The captions in `readme.txt` are matched to these files **by number, not by
name**. Renumbering a screenshot without editing that list silently re-captions
every screenshot after it. If you add, remove or reorder one, update
`== Screenshots ==` in the same change.

Screenshot 3 shows the plugin settings screen, so it goes stale whenever that
screen changes.
