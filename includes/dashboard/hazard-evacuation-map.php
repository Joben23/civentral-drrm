<section id="hazardEvacuationModule" class="civ-hazard-map-module mx-auto w-full max-w-[1600px] space-y-6" aria-labelledby="hazardMapTitle">
  <div class="flex flex-col gap-4 border-b border-slate-200/70 pb-5 dark:border-slate-800 sm:flex-row sm:items-start sm:justify-between">
    <div class="space-y-1.5">
      <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-[0.18em] text-brand-dark dark:text-brand-medium">
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        <span>Disaster Risk Reduction &amp; Emergency Response</span>
      </div>
      <h1 id="hazardMapTitle" class="text-xl font-black tracking-tight text-slate-900 dark:text-white sm:text-2xl">
        Hazard &amp; Evacuation Map System
      </h1>
      <p class="max-w-3xl text-xs font-medium leading-relaxed text-slate-500 dark:text-slate-400 sm:text-sm">
        Monitor hazard-prone areas, evacuation centers, disaster risk levels, and evacuation routes across Caloocan City.
      </p>
    </div>

    <div id="mapDataStatusBadge" class="civ-map-status-badge shrink-0" title="Verified Caloocan hazard and evacuation datasets will be connected during the data integration phase.">
      <span class="civ-map-status-dot" aria-hidden="true"></span>
      <span id="mapDataStatusText">Map Data Status: Caloocan Context</span>
    </div>
  </div>

  <div class="civ-hazard-layout">
    <aside class="civ-map-primary-controls min-w-0 space-y-4" aria-label="Primary hazard map controls">
      <section class="civ-map-card" aria-labelledby="barangaySearchTitle">
        <div class="civ-map-card-heading">
          <span class="civ-map-card-icon"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
          <h2 id="barangaySearchTitle">Search Barangay</h2>
        </div>

        <form id="barangaySearchForm" class="mt-3" role="search" novalidate>
          <label class="sr-only" for="barangaySearchInput">Search barangay</label>
          <div class="relative">
            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[11px] text-slate-400" aria-hidden="true"></i>
            <input
              id="barangaySearchInput"
              type="search"
              placeholder="Search barangay..."
              autocomplete="off"
              role="combobox"
              aria-autocomplete="list"
              aria-controls="barangaySearchSuggestions"
              aria-expanded="false"
              class="civ-map-input w-full py-2.5 pl-9 pr-11 text-xs"
            >
            <button type="submit" class="civ-map-search-button" aria-label="Search barangay">
              <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </button>
            <div
              id="barangaySearchSuggestions"
              class="civ-barangay-suggestions"
              role="listbox"
              aria-label="Matching barangays"
              hidden
            ></div>
          </div>
        </form>
        <div class="mt-2 flex items-start justify-between gap-2">
          <p id="barangaySearchStatus" class="civ-map-helper" role="status" aria-live="polite">
            Barangay records are not yet connected.
          </p>
          <button id="clearBarangaySelectionButton" type="button" class="civ-clear-barangay-selection" hidden>
            Clear selection
          </button>
        </div>
      </section>

      <section class="civ-map-card" aria-labelledby="hazardLayersTitle">
        <div class="civ-map-card-heading">
          <span class="civ-map-card-icon"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span>
          <h2 id="hazardLayersTitle">Hazard Layers</h2>
        </div>

        <div class="mt-3 space-y-2">
          <label class="civ-layer-option">
            <span class="flex min-w-0 items-center gap-2.5">
              <span class="civ-layer-symbol civ-layer-symbol-flood"><i class="fa-solid fa-water" aria-hidden="true"></i></span>
              <span>Flood-Prone Areas</span>
            </span>
            <input type="checkbox" data-map-layer="floodHazards" class="civ-layer-checkbox" aria-describedby="hazardLayerStatus">
          </label>

          <label class="civ-layer-option">
            <span class="flex min-w-0 items-center gap-2.5">
              <span class="civ-layer-symbol civ-layer-symbol-landslide"><i class="fa-solid fa-mountain" aria-hidden="true"></i></span>
              <span>Landslide-Prone Areas</span>
            </span>
            <input type="checkbox" data-map-layer="landslideHazards" class="civ-layer-checkbox" aria-describedby="hazardLayerStatus">
          </label>

          <label class="civ-layer-option">
            <span class="flex min-w-0 items-center gap-2.5">
              <span class="civ-layer-symbol civ-layer-symbol-earthquake"><i class="fa-solid fa-wave-square" aria-hidden="true"></i></span>
              <span>Earthquake / Fault Information</span>
            </span>
            <input type="checkbox" data-map-layer="earthquakeFaults" class="civ-layer-checkbox" aria-describedby="hazardLayerStatus">
          </label>

          <label class="civ-layer-option">
            <span class="flex min-w-0 items-center gap-2.5">
              <span class="civ-layer-symbol civ-layer-symbol-center"><i class="fa-solid fa-house-medical" aria-hidden="true"></i></span>
              <span>Evacuation Centers</span>
            </span>
            <input type="checkbox" data-map-layer="evacuationCenters" class="civ-layer-checkbox" aria-describedby="hazardLayerStatus">
          </label>
        </div>

        <p id="hazardLayerStatus" class="civ-map-empty-state mt-3" role="status" aria-live="polite">
          Hazard datasets are not yet connected.
        </p>
      </section>

      <section class="civ-map-card" aria-labelledby="riskLegendTitle">
        <div class="civ-map-card-heading">
          <span class="civ-map-card-icon"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i></span>
          <h2 id="riskLegendTitle">Risk Level Legend</h2>
        </div>

        <p id="riskLegendContext" class="civ-risk-legend-context mt-3">Project risk classifications</p>
        <div id="riskLegendItems" class="mt-2 grid grid-cols-2 gap-2" aria-label="Project risk classifications">
          <div class="civ-risk-item"><span class="civ-risk-dot civ-risk-low"></span><span>Low</span></div>
          <div class="civ-risk-item"><span class="civ-risk-dot civ-risk-moderate"></span><span>Moderate</span></div>
          <div class="civ-risk-item"><span class="civ-risk-dot civ-risk-high"></span><span>High</span></div>
          <div class="civ-risk-item"><span class="civ-risk-dot civ-risk-critical"></span><span id="highestRiskLegendLabel">Critical</span></div>
        </div>
        <p id="riskLegendHelper" class="civ-map-helper mt-2">Legend only. No risk level has been assigned to any location.</p>
      </section>

    </aside>

    <section class="civ-map-panel min-w-0" aria-labelledby="operationalMapTitle">
      <div class="civ-map-panel-header">
        <div>
          <h2 id="operationalMapTitle" class="text-sm font-black text-slate-800 dark:text-white">Caloocan City Operational Map</h2>
          <p id="operationalMapSubtitle" class="mt-0.5 text-[10px] font-medium text-slate-500 dark:text-slate-400">The city boundary defines the current CIVENTRAL DRRM coverage area.</p>
        </div>
        <span class="civ-basemap-label"><i class="fa-solid fa-draw-polygon" aria-hidden="true"></i> <span id="mapViewModeLabel">Polygon View</span></span>
      </div>

      <div class="civ-map-canvas-wrap">
        <div id="civentralHazardMap" role="application" aria-label="Interactive polygon map of Caloocan City"></div>
        <div id="mapUnavailableMessage" class="civ-map-unavailable hidden" role="alert">
          <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
          <div>
            <strong>Map unavailable</strong>
            <span>The map library could not be loaded. Check the network connection and refresh the page.</span>
          </div>
        </div>
      </div>

      <div id="polygonMapNotice" class="civ-map-data-notice">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <p><strong>Caloocan polygon view.</strong> The city boundary defines the operational canvas; connected development layers remain explicitly labeled as previews.</p>
      </div>
      <div id="draftBarangayPreviewNotice" class="civ-map-data-notice hidden" role="status" aria-live="polite">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <p><strong>Development Preview:</strong> 187 validated barangay boundaries are loaded. Barangays 176-A to 176-F are pending validated GIS boundaries.</p>
      </div>
    </section>

    <aside class="civ-map-context-rail min-w-0" aria-label="Map context and preparedness tools">
      <section class="civ-map-card" aria-labelledby="locationDetailsTitle">
        <div class="civ-map-card-heading">
          <span class="civ-map-card-icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
          <h2 id="locationDetailsTitle">Location Details</h2>
        </div>
        <div id="locationDetailsContent" class="civ-map-empty-state mt-3" aria-live="polite">
          Select a barangay, hazard area, or evacuation center from the map to view information.
        </div>
      </section>

      <section class="civ-map-card civ-preparedness-card" aria-labelledby="preparednessToolsTitle">
        <div class="flex items-start justify-between gap-3">
          <div class="civ-map-card-heading">
            <span class="civ-map-card-icon"><i class="fa-solid fa-kit-medical" aria-hidden="true"></i></span>
            <h2 id="preparednessToolsTitle">Preparedness Tools</h2>
          </div>
          <span class="civ-tool-connection-status"><i class="fa-solid fa-plug-circle-xmark" aria-hidden="true"></i> Not Connected</span>
        </div>

        <div class="civ-preparedness-tabs mt-3" role="tablist" aria-label="Preparedness tools">
          <button
            id="evacuationRouteTab"
            type="button"
            class="civ-preparedness-tab is-active"
            role="tab"
            aria-selected="true"
            aria-controls="evacuationRoutePanel"
            tabindex="0"
          >Evacuation Route</button>
          <button
            id="floodForecastTab"
            type="button"
            class="civ-preparedness-tab"
            role="tab"
            aria-selected="false"
            aria-controls="floodForecastPanel"
            tabindex="-1"
          >Flood Forecast</button>
        </div>

        <div id="evacuationRoutePanel" class="civ-preparedness-panel" role="tabpanel" aria-labelledby="evacuationRouteTab">
          <h3 id="evacuationRouteTitle" class="sr-only">Evacuation Route</h3>
          <div class="space-y-3">
            <div>
              <label for="routeStartInput" class="civ-map-label">Starting Location</label>
              <input id="routeStartInput" type="text" value="Not connected" class="civ-map-input mt-1.5 w-full px-3 py-2.5 text-xs" disabled>
            </div>
            <div>
              <label for="routeCenterSelect" class="civ-map-label">Evacuation Center</label>
              <select id="routeCenterSelect" class="civ-map-input mt-1.5 w-full px-3 py-2.5 text-xs" disabled>
                <option>Not connected</option>
              </select>
            </div>
            <button id="findSafeRouteButton" type="button" class="civ-route-button w-full" disabled>
              <i class="fa-solid fa-route" aria-hidden="true"></i>
              <span>Find Safe Route</span>
            </button>
            <p class="civ-map-helper">Safe routing will become available after verified evacuation centers and hazard data are connected.</p>
          </div>
        </div>

        <div id="floodForecastPanel" class="civ-preparedness-panel" role="tabpanel" aria-labelledby="floodForecastTab" hidden>
          <div class="flex items-center justify-between gap-2">
            <h3 id="floodForecastTitle" class="text-[11px] font-black text-slate-700 dark:text-slate-200">Flood Risk Forecast</h3>
            <span id="floodModelStatus" class="civ-model-status">Not Integrated</span>
          </div>
          <p id="floodForecastContent" class="civ-map-empty-state mt-3" aria-live="polite">Risk prediction is not yet connected.</p>
          <p class="civ-map-helper mt-3">No warning level or flood prediction is shown until a validated forecasting source is integrated.</p>
        </div>
      </section>
    </aside>
  </div>
</section>
