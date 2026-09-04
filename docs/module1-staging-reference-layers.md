# Module 1 staging reference layers

Module 1 staging keeps published CIVENTRAL records as the first-priority data
source. A successful operational response containing zero rows may enable the
separately labelled external or administrative reference display described
below. An operational endpoint failure does not silently fall back to a
reference source.

## Caloocan presentation mask

DENR-MGB flood and rain-induced-landslide layers are rendered directly from
their official cached public MapServers. Leaflet is configured with native
cached zooms 6 through 14 and display zooms through 18; zooms above 14 upscale
native zoom 14 rather than requesting unsupported native tiles.

The outside-city treatment is a presentation-only Leaflet GeoJSON mask. It is
constructed from a world rectangle with the Caloocan polygon components as
holes and uses SVG's even-odd fill rule. It sits above MGB raster tiles and
below feature layers. It neither edits nor rehosts the official imagery. The
Caloocan outline is redrawn in a higher pane so it remains legible over raster
and vector hazards.

## Fault reference

When the operational fault endpoint succeeds with zero rows, staging may draw
the official DOST-PHIVOLCS Active Fault WMS as an image-only reference. No raw
PHIVOLCS fault geometry is queried, stored, or written to Supabase. The map
continues to state that no mapped active fault in this dataset intersects
Caloocan and describes nearest-fault context as approximate reference only.

## Administrative center reference

The staging-only endpoint requires an authenticated administrative session and
the Module 1 VIEW permission. It projects only the fixed, guarded set of 15
DRAFT and INACTIVE center rows and returns minimized reference fields. It is
not available through the citizen hazard-map API. These markers never populate
the operational route destination selector; only published eligible centers
and approved stored routes participate in operational routing.

## Pane order

The explicit panes are: base (300), MGB raster (330), outside-city mask (340),
hazard polygons (360), barangays (370), PHIVOLCS WMS (380), operational lines
(390), city outline (410), center markers (600), routes (620), and selected
location overlays (640).
