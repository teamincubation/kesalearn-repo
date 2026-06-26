<!-- Profile Photo Upload Section -->
<div style="background: var(--bg-secondary); border-radius: var(--radius-md); padding: 24px 20px; margin-bottom: 30px;">
    <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 20px; color: var(--text-primary);">Upload Profile Photo</h3>
    
    <!-- Desktop Layout: Side-by-side -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; max-width: 600px;">
        <!-- Upload Area -->
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 10px; color: var(--text-primary); font-size: 14px;">Select Photo</label>
            <div id="uploadArea" style="border: 2px dashed var(--border-color); border-radius: var(--radius-md); padding: 30px 16px; text-align: center; cursor: pointer; background: var(--bg-primary); transition: all 0.3s ease; min-height: 200px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <svg width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--text-muted); margin-bottom: 10px;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <p style="margin: 0; color: var(--text-primary); font-weight: 500; font-size: 14px;">Click or drag</p>
                <p style="margin: 4px 0 0 0; color: var(--text-muted); font-size: 12px;">JPEG, PNG • Max 5MB</p>
            </div>
            <input type="file" id="imageInput" accept="image/jpeg,image/png,image/webp" style="display: none;">
        </div>
        
        <!-- Preview Placeholder -->
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 10px; color: var(--text-primary); font-size: 14px;">Crop in modal</label>
            <div style="width: 100%; aspect-ratio: 1; background: var(--bg-primary); border-radius: var(--radius-md); border: 1px solid var(--border-color); overflow: hidden; display: flex; align-items: center; justify-content: center; min-height: 200px;">
                <div style="text-align: center; color: var(--text-muted);">
                    <svg width="40" height="40" fill="currentColor" viewBox="0 0 16 16" style="opacity: 0.5; margin-bottom: 6px;">
                        <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0zm.5 6a.5.5 0 0 1 .5.5v1.5a.5.5 0 0 1-.5.5h-5a.5.5 0 0 1-.5-.5v-1.5a.5.5 0 0 1 .5-.5h5z"/>
                    </svg>
                    <p style="margin: 0; font-size: 13px; font-weight: 500;">Upload to preview</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Crop Modal -->
    <div id="cropModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 9999; padding: 20px; align-items: center; justify-content: center;">
        <div style="background: var(--bg-primary); border-radius: var(--radius-lg); width: 100%; max-width: 450px; padding: 24px;">
            <h3 style="margin: 0 0 18px 0; font-size: 1rem; color: var(--text-primary);">Crop & Adjust Photo</h3>
            
            <!-- Image Preview in Modal -->
            <div style="width: 100%; aspect-ratio: 1; background: var(--bg-secondary); border-radius: var(--radius-md); overflow: hidden; margin-bottom: 18px;">
                <img id="cropImage" style="width: 100%; height: 100%; object-fit: cover; display: block;">
            </div>
            
            <!-- Zoom Control -->
            <div style="margin-bottom: 18px;">
                <label style="font-size: 12px; color: var(--text-muted); margin-bottom: 8px; display: block; font-weight: 500;">Zoom Level</label>
                <input type="range" id="zoomSlider" min="100" max="300" value="100" style="width: 100%; cursor: pointer; height: 6px;">
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 6px; text-align: right;" id="zoomValue">100%</div>
            </div>
            
            <!-- Action Buttons -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <button type="button" onclick="closeCropModal()" style="padding: 11px; border: 1px solid var(--border-color); background: var(--bg-secondary); border-radius: var(--radius-sm); cursor: pointer; font-weight: 600; color: var(--text-primary); font-size: 14px;">Cancel</button>
                <button type="button" id="saveCropBtn" onclick="saveCroppedImage()" style="padding: 11px; border: none; background: var(--primary-color, #1565c0); color: white; border-radius: var(--radius-sm); cursor: pointer; font-weight: 600; font-size: 14px;">Save & Continue</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form input for cropped image -->
<input type="hidden" id="croppedImageData" name="cropped_image">

<script>
document.addEventListener('DOMContentLoaded', function() {
    const imageInput = document.getElementById('imageInput');
    const uploadArea = document.getElementById('uploadArea');
    const cropModal = document.getElementById('cropModal');
    const cropImage = document.getElementById('cropImage');
    const zoomSlider = document.getElementById('zoomSlider');
    const zoomValue = document.getElementById('zoomValue');
    const croppedImageData = document.getElementById('croppedImageData');
    const saveCropBtn = document.getElementById('saveCropBtn');
    
    let currentImageData = null;
    let currentZoom = 100;
    
    // Upload area click
    uploadArea.addEventListener('click', () => imageInput.click());
    
    // Drag and drop
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.style.background = 'var(--bg-tertiary, #f0f0f0)';
        uploadArea.style.borderColor = 'var(--primary-color, #1565c0)';
    });
    
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.style.background = 'var(--bg-primary)';
        uploadArea.style.borderColor = 'var(--border-color)';
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.style.background = 'var(--bg-primary)';
        uploadArea.style.borderColor = 'var(--border-color)';
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            imageInput.files = files;
            handleImageSelect();
        }
    });
    
    // File input change
    imageInput.addEventListener('change', handleImageSelect);
    
    function handleImageSelect() {
        const file = imageInput.files[0];
        if (!file) return;
        
        if (!file.type.startsWith('image/')) {
            alert('Please select a valid image file');
            return;
        }
        
        if (file.size > 5 * 1024 * 1024) {
            alert('Image must be less than 5MB');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = (e) => {
            currentImageData = e.target.result;
            cropImage.src = currentImageData;
            zoomSlider.value = 100;
            currentZoom = 100;
            zoomValue.textContent = '100%';
            cropImage.style.transform = 'scale(1)';
            cropModal.style.display = 'flex';
        };
        reader.readAsDataURL(file);
    }
    
    zoomSlider.addEventListener('input', (e) => {
        currentZoom = parseInt(e.target.value);
        zoomValue.textContent = currentZoom + '%';
        cropImage.style.transform = `scale(${currentZoom / 100})`;
    });
    
    window.closeCropModal = function() {
        cropModal.style.display = 'none';
        imageInput.value = '';
    };
    
    window.saveCroppedImage = function() {
        saveCropBtn.disabled = true;
        saveCropBtn.textContent = 'Saving...';
        
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const img = new Image();
        img.crossOrigin = 'anonymous';
        
        img.onload = function() {
            try {
                const size = 1024;
                canvas.width = size;
                canvas.height = size;
                
                // Center crop
                const zoomFactor = currentZoom / 100;
                const sourceSize = Math.min(img.width, img.height) / zoomFactor;
                const sx = (img.width - sourceSize) / 2;
                const sy = (img.height - sourceSize) / 2;
                
                ctx.drawImage(img, sx, sy, sourceSize, sourceSize, 0, 0, size, size);
                
                const dataUrl = canvas.toDataURL('image/jpeg', 0.92);
                croppedImageData.value = dataUrl;
                
                console.log('[v0] Image cropped successfully, submitting form');
                
                // Submit the form with the cropped image data
                const form = croppedImageData.closest('form');
                if (form) {
                    form.submit();
                } else {
                    console.error('[v0] Could not find parent form to submit');
                    alert('Error: Form not found. Please try again.');
                    saveCropBtn.disabled = false;
                    saveCropBtn.textContent = 'Save & Continue';
                }
            } catch (error) {
                console.error('[v0] Error during crop:', error);
                alert('Failed to process image. Please try again.');
                saveCropBtn.disabled = false;
                saveCropBtn.textContent = 'Save & Continue';
            }
        };
        
        img.onerror = function() {
            console.error('[v0] Failed to load image for cropping');
            alert('Failed to process image. Please try again.');
            saveCropBtn.disabled = false;
            saveCropBtn.textContent = 'Save & Continue';
        };
        
        img.src = currentImageData;
    };
});
</script>
    
    <!-- Crop Modal -->
    <div id="cropModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 9999; padding: 20px; align-items: center; justify-content: center;">
        <div style="background: var(--bg-primary); border-radius: var(--radius-lg); width: 100%; max-width: 450px; padding: 24px;">
            <h3 style="margin: 0 0 18px 0; font-size: 1rem; color: var(--text-primary);">Crop & Adjust Photo</h3>
            
            <!-- Image Preview in Modal -->
            <div style="width: 100%; aspect-ratio: 1; background: var(--bg-secondary); border-radius: var(--radius-md); overflow: hidden; margin-bottom: 18px;">
                <img id="cropImage" style="width: 100%; height: 100%; object-fit: cover;">
            </div>
            
            <!-- Zoom Control -->
            <div style="margin-bottom: 18px;">
                <label style="font-size: 12px; color: var(--text-muted); margin-bottom: 8px; display: block; font-weight: 500;">Zoom Level</label>
                <input type="range" id="zoomSlider" min="100" max="300" value="100" style="width: 100%; cursor: pointer; height: 6px;">
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 6px; text-align: right;" id="zoomValue">100%</div>
            </div>
            
            <!-- Action Buttons -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <button type="button" onclick="closeCropModal()" style="padding: 11px; border: 1px solid var(--border-color); background: var(--bg-secondary); border-radius: var(--radius-sm); cursor: pointer; font-weight: 600; color: var(--text-primary); font-size: 14px;">Cancel</button>
                <button type="button" onclick="saveCroppedImage()" style="padding: 11px; border: none; background: var(--primary-color, #1565c0); color: white; border-radius: var(--radius-sm); cursor: pointer; font-weight: 600; font-size: 14px;">Save & Continue</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form input for cropped image -->
<input type="hidden" id="croppedImageData" name="cropped_image">

<script>
document.addEventListener('DOMContentLoaded', function() {
    const imageInput = document.getElementById('imageInput');
    const uploadArea = document.getElementById('uploadArea');
    const cropModal = document.getElementById('cropModal');
    const cropImage = document.getElementById('cropImage');
    const zoomSlider = document.getElementById('zoomSlider');
    const zoomValue = document.getElementById('zoomValue');
    const previewImage = document.getElementById('previewImage');
    const croppedImageData = document.getElementById('croppedImageData');
    
    let currentImageData = null;
    let currentZoom = 100;
    
    // Upload area click
    uploadArea.addEventListener('click', () => imageInput.click());
    
    // Drag and drop
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.style.background = 'var(--bg-tertiary)';
        uploadArea.style.borderColor = 'var(--primary-color, #1565c0)';
    });
    
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.style.background = 'var(--bg-primary)';
        uploadArea.style.borderColor = 'var(--border-color)';
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.style.background = 'var(--bg-primary)';
        uploadArea.style.borderColor = 'var(--border-color)';
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            imageInput.files = files;
            handleImageSelect();
        }
    });
    
    // File input change
    imageInput.addEventListener('change', handleImageSelect);
    
    function handleImageSelect() {
        const file = imageInput.files[0];
        if (!file) return;
        
        if (!file.type.startsWith('image/')) {
            alert('Please select a valid image file');
            return;
        }
        
        if (file.size > 5 * 1024 * 1024) {
            alert('Image must be less than 5MB');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = (e) => {
            currentImageData = e.target.result;
            cropImage.src = currentImageData;
            zoomSlider.value = 100;
            currentZoom = 100;
            zoomValue.textContent = '100%';
            cropImage.style.transform = 'scale(1)';
            cropModal.style.display = 'flex';
        };
        reader.readAsDataURL(file);
    }
    
    zoomSlider.addEventListener('input', (e) => {
        currentZoom = parseInt(e.target.value);
        zoomValue.textContent = currentZoom + '%';
        cropImage.style.transform = `scale(${currentZoom / 100})`;
    });
    
    window.closeCropModal = function() {
        cropModal.style.display = 'none';
        imageInput.value = '';
    };
    
    window.saveCroppedImage = function() {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const img = new Image();
        img.crossOrigin = 'anonymous';
        
        img.onload = function() {
            const size = 1024;
            canvas.width = size;
            canvas.height = size;
            
            // Center crop
            const zoomFactor = currentZoom / 100;
            const sourceSize = Math.min(img.width, img.height) / zoomFactor;
            const sx = (img.width - sourceSize) / 2;
            const sy = (img.height - sourceSize) / 2;
            
            ctx.drawImage(img, sx, sy, sourceSize, sourceSize, 0, 0, size, size);
            
            const dataUrl = canvas.toDataURL('image/jpeg', 0.92);
            croppedImageData.value = dataUrl;
            
            // Update preview with proper path
            if (previewImage) {
                previewImage.src = dataUrl;
            }
            
            console.log('[v0] Image cropped successfully. Size: ' + (dataUrl.length / 1024).toFixed(2) + 'KB');
            closeCropModal();
        };
        
        img.onerror = function() {
            console.error('[v0] Failed to load image for cropping');
            alert('Failed to process image. Please try again.');
        };
        
        img.src = currentImageData;
    };
});
</script>
