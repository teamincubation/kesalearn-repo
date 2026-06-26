<!-- Mandatory WhatsApp Update Modal Component -->
<?php if (isset($showWhatsAppModal) && $showWhatsAppModal): ?>
<div id="whatsappModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 10000;">
    <div style="background: white; border-radius: 16px; padding: 40px; max-width: 420px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: slideUp 0.3s ease;">
        <div style="text-align: center; margin-bottom: 24px;">
            <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #25D366 0%, #128C7E 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; color: white; font-size: 32px;">
                💬
            </div>
            <h2 style="margin: 0 0 8px 0; font-size: 1.5rem; color: #1a1a1a;">Update Your WhatsApp Number</h2>
            <p style="margin: 0; color: #666; font-size: 0.9rem;">We need your WhatsApp to send you important updates about your registered events</p>
        </div>

        <form method="POST" onsubmit="handleWhatsAppSubmit(event);">
            <?php echo csrfField(); ?>
            <input type="hidden" name="update_whatsapp" value="1">
            <input type="hidden" name="ajax" value="1">
            
            <?php if (!empty($whatsappError)): ?>
            <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
                <?php echo $whatsappError; ?>
            </div>
            <?php endif; ?>

            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #1a1a1a; font-size: 0.9rem;">WhatsApp Number</label>
                <div style="display: flex; align-items: center; border: 2px solid #ddd; border-radius: 8px; padding: 0 8px; transition: border-color 0.2s; gap: 0;">
                    <select id="countryCodeSelect" name="country_code" style="padding: 12px; border: none; outline: none; font-size: 1rem; font-weight: 600; color: #25D366; background: white; cursor: pointer; min-width: 70px;">
                        <option value="">+CC</option>
                    </select>
                    <span style="color: #ddd; padding: 0 4px;">|</span>
                    <input type="tel" name="whatsapp" id="whatsappInput" placeholder="Enter your number" style="flex: 1; border: none; padding: 12px 0; font-size: 1rem; outline: none;" required>
                </div>
                <small style="color: #999; margin-top: 4px; display: block;">Select your country code from the dropdown. (e.g., +91 for India, +1 for USA)</small>
            </div>

            <button type="submit" id="updateBtn" style="width: 100%; padding: 12px; background: linear-gradient(135deg, #25D366 0%, #128C7E 100%); color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 1rem; cursor: pointer; transition: transform 0.2s; margin-bottom: 12px;">
                Continue
            </button>

            <p style="text-align: center; color: #999; font-size: 0.8rem; margin: 0;">Your WhatsApp number will be kept confidential and used only for event updates</p>
        </form>
    </div>
</div>

<style>
@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<script>
// Comprehensive country code mapping with names
const countryCodesData = [
    { code: 'IN', country: 'India', dial: '91' },
    { code: 'US', country: 'United States', dial: '1' },
    { code: 'GB', country: 'United Kingdom', dial: '44' },
    { code: 'AU', country: 'Australia', dial: '61' },
    { code: 'CA', country: 'Canada', dial: '1' },
    { code: 'NZ', country: 'New Zealand', dial: '64' },
    { code: 'SG', country: 'Singapore', dial: '65' },
    { code: 'MY', country: 'Malaysia', dial: '60' },
    { code: 'TH', country: 'Thailand', dial: '66' },
    { code: 'PH', country: 'Philippines', dial: '63' },
    { code: 'ID', country: 'Indonesia', dial: '62' },
    { code: 'BD', country: 'Bangladesh', dial: '880' },
    { code: 'PK', country: 'Pakistan', dial: '92' },
    { code: 'LK', country: 'Sri Lanka', dial: '94' },
    { code: 'AE', country: 'United Arab Emirates', dial: '971' },
    { code: 'SA', country: 'Saudi Arabia', dial: '966' },
    { code: 'ZA', country: 'South Africa', dial: '27' },
    { code: 'NG', country: 'Nigeria', dial: '234' },
    { code: 'EG', country: 'Egypt', dial: '20' },
    { code: 'KE', country: 'Kenya', dial: '254' },
    { code: 'DE', country: 'Germany', dial: '49' },
    { code: 'FR', country: 'France', dial: '33' },
    { code: 'IT', country: 'Italy', dial: '39' },
    { code: 'ES', country: 'Spain', dial: '34' },
    { code: 'SE', country: 'Sweden', dial: '46' },
    { code: 'NL', country: 'Netherlands', dial: '31' },
    { code: 'BE', country: 'Belgium', dial: '32' },
    { code: 'CH', country: 'Switzerland', dial: '41' },
    { code: 'AT', country: 'Austria', dial: '43' },
    { code: 'PL', country: 'Poland', dial: '48' },
    { code: 'BR', country: 'Brazil', dial: '55' },
    { code: 'MX', country: 'Mexico', dial: '52' },
    { code: 'AR', country: 'Argentina', dial: '54' },
    { code: 'CL', country: 'Chile', dial: '56' },
    { code: 'CO', country: 'Colombia', dial: '57' },
    { code: 'JP', country: 'Japan', dial: '81' },
    { code: 'KR', country: 'South Korea', dial: '82' },
    { code: 'CN', country: 'China', dial: '86' },
    { code: 'HK', country: 'Hong Kong', dial: '852' },
    { code: 'TW', country: 'Taiwan', dial: '886' },
    { code: 'VN', country: 'Vietnam', dial: '84' },
    { code: 'TH', country: 'Thailand', dial: '66' },
    { code: 'RU', country: 'Russia', dial: '7' },
    { code: 'TR', country: 'Turkey', dial: '90' },
    { code: 'IL', country: 'Israel', dial: '972' }
];

// Map for quick lookup
const countryDialCodes = {};
countryCodesData.forEach(item => {
    countryDialCodes[item.code] = item.dial;
});

// Populate dropdown with country codes
function populateCountryCodeDropdown() {
    const select = document.getElementById('countryCodeSelect');
    
    // Sort by country name
    countryCodesData.sort((a, b) => a.country.localeCompare(b.country));
    
    countryCodesData.forEach(item => {
        const option = document.createElement('option');
        option.value = item.dial;
        option.textContent = `+${item.dial} ${item.country}`;
        select.appendChild(option);
    });
}

// Detect user's country and set country code
async function detectAndSetCountryCode() {
    try {
        // Using ipapi.co free API for geolocation (no API key needed)
        const response = await fetch('https://ipapi.co/json/', { signal: AbortSignal.timeout(3000) });
        const data = await response.json();
        
        if (data.country_code && countryDialCodes[data.country_code]) {
            document.getElementById('countryCodeSelect').value = countryDialCodes[data.country_code];
            console.log('[v0] Country code set to: +' + countryDialCodes[data.country_code]);
        } else {
            // Fallback to India if detection fails
            document.getElementById('countryCodeSelect').value = '91';
        }
    } catch (err) {
        console.log('[v0] Could not detect country, defaulting to +91 (India)');
        document.getElementById('countryCodeSelect').value = '91'; // Default to India
    }
}

// Initialize dropdown and detect country on modal load
document.addEventListener('DOMContentLoaded', function() {
    populateCountryCodeDropdown();
    detectAndSetCountryCode();
});

// Validate phone number input (only allow digits)
document.getElementById('whatsappInput').addEventListener('input', function(e) {
    this.value = this.value.replace(/\D/g, '');
});

function handleWhatsAppSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const countryCode = document.getElementById('countryCodeSelect').value.trim();
    const phoneNumber = document.getElementById('whatsappInput').value.replace(/\D/g, '');
    const updateBtn = document.getElementById('updateBtn');

    if (!countryCode) {
        alert('Please select a country code');
        return;
    }
    if (phoneNumber.length < 6) {
        alert('Please enter a valid phone number');
        return;
    }
    const fullNumber = countryCode + phoneNumber;
    if (fullNumber.length < 10) {
        alert('Please enter a valid WhatsApp number (at least 10 digits total)');
        return;
    }

    updateBtn.disabled = true;
    updateBtn.textContent = 'Saving...';

    const formData = new FormData(form);
    formData.set('whatsapp', fullNumber);

    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(r => {
        if (!r.ok) throw new Error('Network error: ' + r.status);
        return r.text().then(text => {
            // Strip any stray HTML before the JSON (safety net)
            const jsonStart = text.indexOf('{');
            if (jsonStart === -1) throw new Error('No JSON in response');
            return JSON.parse(text.slice(jsonStart));
        });
    })
    .then(data => {
        if (data.success) {
            // Mark collected in sessionStorage so even if the page reloads
            // before the next server-side check, the modal stays hidden.
            try { sessionStorage.setItem('wa_collected', '1'); } catch(_) {}

            // Remove modal from DOM entirely — no reload needed.
            const modal = document.getElementById('whatsappModal');
            if (modal) {
                modal.style.transition = 'opacity 0.25s';
                modal.style.opacity = '0';
                setTimeout(() => modal.remove(), 280);
            }

            // Show a non-blocking success toast instead of alert
            showWaToast('WhatsApp number saved successfully!');
        } else {
            updateBtn.disabled = false;
            updateBtn.textContent = 'Continue';
            alert(data.error || 'Error saving WhatsApp number. Please try again.');
        }
    })
    .catch(err => {
        updateBtn.disabled = false;
        updateBtn.textContent = 'Continue';
        alert('Error saving WhatsApp number. Please check your connection and try again.');
    });
}

function showWaToast(msg) {
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#25D366;color:#fff;padding:12px 24px;border-radius:50px;font-size:0.9rem;font-weight:600;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,0.18);pointer-events:none;transition:opacity 0.4s;';
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 420); }, 2800);
}

// If this session already confirmed WA collected, hide the modal immediately
// (guards against a server-side race where the reload happens before DB propagates)
(function() {
    try {
        if (sessionStorage.getItem('wa_collected') === '1') {
            var m = document.getElementById('whatsappModal');
            if (m) m.remove();
        }
    } catch(_) {}
})();
</script>
<?php endif; ?>
