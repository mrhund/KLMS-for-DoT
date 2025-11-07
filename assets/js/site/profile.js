import Cropper from 'cropperjs';

const MAX_CROP_WIDTH = 700;
const MAX_CROP_HEIGHT = 500;

const prepareImageDataUrl = (src, maxWidth, maxHeight) => {
    return new Promise((resolve) => {
        if (!src) {
            resolve(src);
            return;
        }

        const image = new Image();
        image.onload = () => {
            if (!image.width || !image.height) {
                resolve(src);
                return;
            }

            const scaleWidth = maxWidth && image.width > maxWidth ? maxWidth / image.width : 1;
            const scaleHeight = maxHeight && image.height > maxHeight ? maxHeight / image.height : 1;
            const scale = Math.min(scaleWidth, scaleHeight);

            if (scale >= 1) {
                resolve(src);
                return;
            }

            const canvas = document.createElement('canvas');
            canvas.width = Math.round(image.width * scale);
            canvas.height = Math.round(image.height * scale);
            const context = canvas.getContext('2d');

            if (!context) {
                resolve(src);
                return;
            }

            context.drawImage(image, 0, 0, canvas.width, canvas.height);

            const mimeMatch = /^data:(.*?);/.exec(src);
            const mimeType = mimeMatch && mimeMatch[1] ? mimeMatch[1] : 'image/jpeg';
            const quality = mimeType === 'image/jpeg' ? 0.92 : undefined;
            resolve(canvas.toDataURL(mimeType, quality));
        };
        image.onerror = () => resolve(src);
        image.src = src;
    });
};

const initProfileImageEditor = () => {
    const container = document.querySelector('[data-profile-image-field]');
    if (!container) {
        return;
    }

    const fileInput = container.querySelector('.js-profile-image-input');
    const selectButton = container.querySelector('.js-profile-image-select');
    const removeButton = container.querySelector('.js-profile-image-remove');
    const deleteField = container.querySelector('.js-profile-image-delete');
    const previewImage = container.querySelector('#profile-image-preview');
    const placeholder = container.querySelector('#profile-image-placeholder');
    const modalElement = document.getElementById('profileImageCropModal');
    const cropperImage = modalElement ? modalElement.querySelector('#profile-image-crop') : null;
    const cropSave = document.getElementById('profileImageCropSave');
    const cropCancel = document.getElementById('profileImageCropCancel');
    const modalCloseButton = modalElement ? modalElement.querySelector('.close') : null;

    if (!fileInput || !previewImage || !placeholder) {
        return;
    }

    const CropperLib = Cropper;

    let cropper = null;
    let generatedObjectUrl = null;
    let pendingDataUrl = null;
    let cropInProgress = false;
    let cropConfirmed = false;
    const useJqueryModal = !!(window.jQuery && typeof window.jQuery.fn.modal === 'function');

    const setDeleteField = (state) => {
        if (!deleteField) {
            return;
        }

        if (deleteField.type === 'checkbox') {
            deleteField.checked = state;
        } else {
            deleteField.value = state ? '1' : '';
        }
    };

    const revokeGeneratedUrl = () => {
        if (generatedObjectUrl) {
            URL.revokeObjectURL(generatedObjectUrl);
            generatedObjectUrl = null;
        }
    };

    const updatePreview = (src, markAsInitial = false, isGenerated = false) => {
        if (isGenerated) {
            revokeGeneratedUrl();
            generatedObjectUrl = src;
        } else if (!src) {
            revokeGeneratedUrl();
        }

        if (src) {
            previewImage.src = src;
            previewImage.style.display = 'block';
            placeholder.style.display = 'none';
            if (removeButton) {
                removeButton.style.display = '';
            }
        } else {
            previewImage.removeAttribute('src');
            previewImage.style.display = 'none';
            placeholder.style.display = '';
            if (removeButton) {
                removeButton.style.display = 'none';
            }
        }

        if (markAsInitial) {
            previewImage.dataset.initialSrc = src || '';
            previewImage.dataset.hasImage = src ? '1' : '0';
        }
    };

    const initModalCropper = () => {
        if (!pendingDataUrl || !cropperImage || !CropperLib) {
            return;
        }

        if (cropper) {
            cropper.destroy();
            cropper = null;
        }

        cropperImage.src = pendingDataUrl;
        cropper = new CropperLib(cropperImage, {
            aspectRatio: 1,
            viewMode: 1,
            autoCropArea: 1,
            movable: true,
            zoomable: true,
            rotatable: false,
            scalable: false,
        });
    };

    const teardownModalCropper = () => {
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
        if (cropperImage) {
            cropperImage.removeAttribute('src');
        }
    };

    const handleModalHidden = () => {
        teardownModalCropper();
        if (cropInProgress && !cropConfirmed) {
            fileInput.value = '';
            const restored = previewImage.dataset.initialSrc || '';
            updatePreview(restored, false);
        }
        cropInProgress = false;
        cropConfirmed = false;
        pendingDataUrl = null;
    };

    const showModal = () => {
        if (!modalElement) {
            return;
        }

        if (useJqueryModal) {
            window.jQuery(modalElement).modal({ backdrop: 'static', keyboard: false, show: true });
        } else {
            modalElement.classList.add('show');
            modalElement.style.display = 'block';
            modalElement.removeAttribute('aria-hidden');
            modalElement.setAttribute('aria-modal', 'true');
            document.body.classList.add('modal-open');
            document.body.style.overflow = 'hidden';
            initModalCropper();
        }
    };

    const hideModal = () => {
        if (!modalElement) {
            return;
        }

        if (useJqueryModal) {
            window.jQuery(modalElement).modal('hide');
        } else {
            modalElement.classList.remove('show');
            modalElement.style.display = 'none';
            modalElement.setAttribute('aria-hidden', 'true');
            modalElement.removeAttribute('aria-modal');
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            handleModalHidden();
        }
    };

    const initialSrc = previewImage.dataset.initialSrc || '';
    if (previewImage.dataset.hasImage === '1' && initialSrc) {
        updatePreview(initialSrc, false);
    } else {
        updatePreview('', false);
    }

    if (modalElement) {
        if (useJqueryModal) {
            window.jQuery(modalElement).on('shown.bs.modal', initModalCropper);
            window.jQuery(modalElement).on('hidden.bs.modal', handleModalHidden);
        } else {
            modalElement.addEventListener('shown.bs.modal', initModalCropper);
            modalElement.addEventListener('hidden.bs.modal', handleModalHidden);
        }
    }

    if (modalCloseButton) {
        modalCloseButton.addEventListener('click', () => {
            if (!useJqueryModal) {
                hideModal();
            }
        });
    }

    if (cropCancel) {
        cropCancel.addEventListener('click', () => {
            if (!useJqueryModal) {
                hideModal();
            }
        });
    }

    if (selectButton) {
        selectButton.addEventListener('click', () => fileInput.click());
    }

    if (removeButton) {
        removeButton.addEventListener('click', () => {
            fileInput.value = '';
            setDeleteField(true);
            updatePreview('', true);
            if (cropInProgress) {
                hideModal();
            }
        });
    }

    if (cropSave) {
        cropSave.addEventListener('click', () => {
            if (!cropper) {
                return;
            }

            const canvas = cropper.getCroppedCanvas({
                width: 200,
                height: 200,
                imageSmoothingQuality: 'high',
            });

            if (!canvas) {
                return;
            }

            setDeleteField(false);

            canvas.toBlob((blob) => {
                if (!blob) {
                    return;
                }

                const originalFile = fileInput.files[0];
                const fileName = originalFile ? originalFile.name : 'profilbild.jpg';
                const croppedFile = new File([blob], fileName, { type: blob.type, lastModified: Date.now() });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(croppedFile);
                fileInput.files = dataTransfer.files;

                const objectUrl = URL.createObjectURL(croppedFile);
                updatePreview(objectUrl, true, true);
                cropConfirmed = true;
                cropInProgress = false;
                hideModal();
            }, 'image/jpeg');
        });
    }

    fileInput.addEventListener('change', (event) => {
        const file = event.target.files[0];
        if (!file) {
            return;
        }

        setDeleteField(false);
        cropInProgress = false;
        cropConfirmed = false;
        pendingDataUrl = null;

        const reader = new FileReader();
        reader.onload = (ev) => {
            const result = ev.target ? ev.target.result : null;
            if (!result) {
                return;
            }

            prepareImageDataUrl(result, MAX_CROP_WIDTH, MAX_CROP_HEIGHT).then((processedResult) => {
                if (!CropperLib || !modalElement) {
                    updatePreview(processedResult, true);
                    return;
                }

                pendingDataUrl = processedResult;
                cropInProgress = true;
                showModal();
            });
        };
        reader.readAsDataURL(file);
    });
};

const bootstrapProfileImageEditor = () => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProfileImageEditor);
    } else {
        initProfileImageEditor();
    }
};

bootstrapProfileImageEditor();

export default initProfileImageEditor;
