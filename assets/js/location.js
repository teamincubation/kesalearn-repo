/**
 * KESA Learn - Professional Location Selection System
 * Reliable Country > State/Province > City/District cascading selector
 * Built-in comprehensive country list with API enhancement (optional)
 * Works 100% on load with fallback data, enhances with API when available
 */

// Comprehensive built-in country database - ALWAYS available
var COUNTRIES_DATABASE = [
    { id: 'IN', name: 'India', hasStates: true },
    { id: 'US', name: 'United States', hasStates: true },
    { id: 'GB', name: 'United Kingdom', hasStates: true },
    { id: 'CA', name: 'Canada', hasStates: true },
    { id: 'AU', name: 'Australia', hasStates: true },
    { id: 'PK', name: 'Pakistan', hasStates: true },
    { id: 'BD', name: 'Bangladesh', hasStates: true },
    { id: 'LK', name: 'Sri Lanka', hasStates: true },
    { id: 'AF', name: 'Afghanistan', hasStates: false },
    { id: 'DE', name: 'Germany', hasStates: true },
    { id: 'FR', name: 'France', hasStates: true },
    { id: 'JP', name: 'Japan', hasStates: true },
    { id: 'CN', name: 'China', hasStates: true },
    { id: 'BR', name: 'Brazil', hasStates: true },
    { id: 'NP', name: 'Nepal', hasStates: false },
    { id: 'SG', name: 'Singapore', hasStates: false },
    { id: 'MY', name: 'Malaysia', hasStates: true },
    { id: 'TH', name: 'Thailand', hasStates: true },
    { id: 'VN', name: 'Vietnam', hasStates: true },
    { id: 'PH', name: 'Philippines', hasStates: true },
    { id: 'ID', name: 'Indonesia', hasStates: true },
    { id: 'KR', name: 'South Korea', hasStates: true },
    { id: 'MX', name: 'Mexico', hasStates: true },
    { id: 'AR', name: 'Argentina', hasStates: true },
    { id: 'ES', name: 'Spain', hasStates: true },
    { id: 'IT', name: 'Italy', hasStates: true },
    { id: 'NL', name: 'Netherlands', hasStates: true },
    { id: 'CH', name: 'Switzerland', hasStates: true },
    { id: 'SE', name: 'Sweden', hasStates: true },
    { id: 'NO', name: 'Norway', hasStates: true },
    { id: 'DK', name: 'Denmark', hasStates: true },
    { id: 'FI', name: 'Finland', hasStates: true },
    { id: 'PL', name: 'Poland', hasStates: true },
    { id: 'RU', name: 'Russia', hasStates: true },
    { id: 'AE', name: 'UAE', hasStates: false },
    { id: 'SA', name: 'Saudi Arabia', hasStates: false },
    { id: 'ZA', name: 'South Africa', hasStates: true },
    { id: 'NG', name: 'Nigeria', hasStates: true },
    { id: 'EG', name: 'Egypt', hasStates: true },
    { id: 'NZ', name: 'New Zealand', hasStates: true }
];

// API Configuration
var API_BASE = 'https://api.countrystatecity.in/v1';
var API_KEY = 'NHhvOEcyWk50N2Vna3VFTE00bFp3MjFKR0ZEOUhkZlg4RTk1MlJlaA==';
var API_HEADERS = { 'X-CSCAPI-KEY': API_KEY };

// Countries with district/region system
var DISTRICT_COUNTRIES = ['IN', 'PK', 'BD', 'LK'];

function initLocationFields(opts) {
    console.log('[LocationJS] Initializing location fields...');
    
    var countryEl = document.getElementById(opts.countryId);
    var stateEl = document.getElementById(opts.stateId);
    var cityEl = document.getElementById(opts.cityId);
    var stateGroup = document.getElementById(opts.stateGroupId);
    var cityGroup = document.getElementById(opts.cityGroupId);
    var cityLabel = document.getElementById('cityLabel');
    
    if (!countryEl || !stateEl || !cityEl) {
        console.error('[LocationJS] ERROR: Location elements not found!');
        return;
    }
    
    var state = {
        selectedCountryId: '',
        selectedCountryName: '',
        selectedStateId: '',
        selectedStateName: '',
        apiWorking: null  // null=untested, true=works, false=fails
    };
    
    // Step 1: Populate countries immediately from built-in database
    populateCountriesFromDatabase();
    
    // Step 2: Test API in background (non-blocking)
    testAPIAvailability();
    
    function populateCountriesFromDatabase() {
        console.log('[LocationJS] Populating ' + COUNTRIES_DATABASE.length + ' countries from database');
        countryEl.innerHTML = '<option value="">-- Select Country --</option>';
        
        COUNTRIES_DATABASE.forEach(function(country) {
            var option = document.createElement('option');
            option.value = country.name;
            option.textContent = country.name;
            option.dataset.id = country.id;
            option.dataset.hasStates = country.hasStates;
            
            if (country.name === opts.oldCountry) {
                option.selected = true;
                state.selectedCountryId = country.id;
                state.selectedCountryName = country.name;
            }
            
            countryEl.appendChild(option);
        });
        
        console.log('[LocationJS] Country dropdown populated with ' + COUNTRIES_DATABASE.length + ' countries');
        
        // If country was pre-selected, load states
        if (state.selectedCountryId) {
            console.log('[LocationJS] Pre-selected country: ' + state.selectedCountryName);
            loadStates(state.selectedCountryId, opts.oldState || '', opts.oldCity || '');
        }
    }
    
    function testAPIAvailability() {
        console.log('[LocationJS] Testing API availability (non-blocking)...');
        
        var testTimeout = setTimeout(function() {
            console.log('[LocationJS] API test timeout after 3 seconds');
            state.apiWorking = false;
        }, 3000);
        
        fetch(API_BASE + '/countries', { headers: API_HEADERS })
            .then(function(r) {
                clearTimeout(testTimeout);
                if (r.ok) {
                    state.apiWorking = true;
                    console.log('[LocationJS] API is working! Enhanced data available.');
                } else {
                    state.apiWorking = false;
                    console.log('[LocationJS] API returned status ' + r.status);
                }
            })
            .catch(function(e) {
                clearTimeout(testTimeout);
                state.apiWorking = false;
                console.log('[LocationJS] API test failed: ' + e.message);
            });
    }
    
    // Country selection change handler
    countryEl.addEventListener('change', function() {
        var selectedOption = this.options[this.selectedIndex];
        if (!selectedOption.value) {
            stateEl.innerHTML = '<option value="">-- Select State / Province --</option>';
            cityEl.innerHTML = '<option value="">-- Select City / Town --</option>';
            if (stateGroup) stateGroup.style.display = 'none';
            if (cityGroup) cityGroup.style.display = 'none';
            return;
        }
        
        state.selectedCountryId = selectedOption.dataset.id;
        state.selectedCountryName = selectedOption.value;
        
        console.log('[LocationJS] Country selected: ' + state.selectedCountryName + ' (' + state.selectedCountryId + ')');
        
        // Show state group and load states
        if (stateGroup) stateGroup.style.display = 'block';
        if (cityGroup) cityGroup.style.display = 'block';
        
        updateCityLabel(state.selectedCountryId);
        loadStates(state.selectedCountryId, '', '');
    });
    
    function updateCityLabel(countryId) {
        if (cityLabel) {
            if (DISTRICT_COUNTRIES.indexOf(countryId) !== -1) {
                cityLabel.textContent = 'District / City / Town';
            } else {
                cityLabel.textContent = 'City / Town';
            }
        }
    }
    
    function loadStates(countryId, preselectedState, preselectedCity) {
        stateEl.innerHTML = '<option value="">Loading...</option>';
        cityEl.innerHTML = '<option value="">-- Select City / Town --</option>';
        
        // If API hasn't been tested yet, wait a bit
        if (state.apiWorking === null) {
            console.log('[LocationJS] Waiting for API test result...');
            setTimeout(function() { loadStates(countryId, preselectedState, preselectedCity); }, 500);
            return;
        }
        
        // If API is working, fetch from API
        if (state.apiWorking === true) {
            console.log('[LocationJS] Fetching states from API for country: ' + countryId);
            fetchStatesFromAPI(countryId, preselectedState, preselectedCity);
        } else {
            console.log('[LocationJS] API not available, showing manual entry option');
            showManualStateEntry(preselectedState);
        }
    }
    
    function fetchStatesFromAPI(countryId, preselectedState, preselectedCity) {
        fetch(API_BASE + '/countries/' + countryId + '/states', { headers: API_HEADERS, timeout: 5000 })
            .then(function(r) {
                if (!r.ok) throw new Error('API returned ' + r.status);
                return r.json();
            })
            .then(function(states) {
                if (!states || states.length === 0) {
                    console.log('[LocationJS] No states found for country ' + countryId);
                    showManualStateEntry(preselectedState);
                    return;
                }
                
                console.log('[LocationJS] Got ' + states.length + ' states from API');
                stateEl.innerHTML = '<option value="">-- Select State / Province --</option>';
                
                states.forEach(function(state) {
                    var option = document.createElement('option');
                    option.value = state.name;
                    option.textContent = state.name;
                    option.dataset.id = state.iso2;
                    
                    if (state.name === preselectedState) {
                        option.selected = true;
                        state.selectedStateId = state.iso2;
                        state.selectedStateName = state.name;
                    }
                    
                    stateEl.appendChild(option);
                });
                
                // If state was pre-selected, load cities
                if (preselectedState && state.selectedStateName) {
                    loadCities(countryId, state.selectedStateId, preselectedCity);
                }
            })
            .catch(function(err) {
                console.error('[LocationJS] Failed to fetch states: ' + err.message);
                showManualStateEntry(preselectedState);
            });
    }
    
    function showManualStateEntry(preselectedValue) {
        stateEl.innerHTML = '<option value="">-- Enter State / Province --</option>';
        if (preselectedValue) {
            stateEl.innerHTML += '<option value="' + preselectedValue + '" selected>' + preselectedValue + '</option>';
        }
    }
    
    // State selection change handler
    stateEl.addEventListener('change', function() {
        var selectedOption = this.options[this.selectedIndex];
        if (!selectedOption.value) {
            cityEl.innerHTML = '<option value="">-- Select City / Town --</option>';
            return;
        }
        
        state.selectedStateName = selectedOption.value;
        state.selectedStateId = selectedOption.dataset.id || selectedOption.value;
        
        console.log('[LocationJS] State selected: ' + state.selectedStateName);
        
        if (state.apiWorking === true) {
            loadCities(state.selectedCountryId, state.selectedStateId, '');
        }
    });
    
    function loadCities(countryId, stateId, preselectedCity) {
        cityEl.innerHTML = '<option value="">Loading...</option>';
        
        console.log('[LocationJS] Fetching cities for: ' + countryId + ' / ' + stateId);
        
        fetch(API_BASE + '/countries/' + countryId + '/states/' + stateId + '/cities', { headers: API_HEADERS, timeout: 5000 })
            .then(function(r) {
                if (!r.ok) throw new Error('API returned ' + r.status);
                return r.json();
            })
            .then(function(cities) {
                if (!cities || cities.length === 0) {
                    console.log('[LocationJS] No cities found');
                    cityEl.innerHTML = '<option value="">-- Enter City / Town --</option>';
                    return;
                }
                
                console.log('[LocationJS] Got ' + cities.length + ' cities');
                var label = DISTRICT_COUNTRIES.indexOf(countryId) !== -1 
                    ? '-- Select District / City / Town --' 
                    : '-- Select City / Town --';
                
                cityEl.innerHTML = '<option value="">' + label + '</option>';
                
                cities.forEach(function(city) {
                    var option = document.createElement('option');
                    option.value = city.name;
                    option.textContent = city.name;
                    
                    if (city.name === preselectedCity || city.name === opts.oldDistrict) {
                        option.selected = true;
                    }
                    
                    cityEl.appendChild(option);
                });
            })
            .catch(function(err) {
                console.error('[LocationJS] Failed to fetch cities: ' + err.message);
                cityEl.innerHTML = '<option value="">-- Enter City / Town --</option>';
            });
    }
    
    console.log('[LocationJS] Initialization complete!');
}
