import { escapeHTML } from './utils.js';

export function showToast(message, type = 'success', duration = 4000) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    // Limit maximum visible toasts to prevent clutter
    const maxToasts = 6;
    while (container.children.length >= maxToasts) {
        dismissToast(container.firstElementChild);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    let icon = 'check-circle';
    if (type === 'error') icon = 'exclamation-circle';
    else if (type === 'warning') icon = 'exclamation-triangle';
    else if (type === 'info') icon = 'info-circle';
    
    toast.innerHTML = `
        <div class="toast-icon-wrap">
            <i class="fa fa-${icon}"></i>
        </div>
        <div class="toast-content">
            <span class="toast-message">${escapeHTML(message)}</span>
        </div>
        <button class="toast-close" title="Close">&times;</button>
        ${duration > 0 ? `<div class="toast-progress" style="animation-duration: ${duration}ms;"></div>` : ''}
    `;

    const closeBtn = toast.querySelector('.toast-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            dismissToast(toast);
        });
    }

    container.appendChild(toast);

    if (duration > 0) {
        const timer = setTimeout(() => {
            dismissToast(toast);
        }, duration);

        // Store timer reference for cleanup
        toast._dismissTimer = timer;

        toast.addEventListener('mouseenter', () => {
            const progress = toast.querySelector('.toast-progress');
            if (progress) progress.style.animationPlayState = 'paused';
        });
        toast.addEventListener('mouseleave', () => {
            const progress = toast.querySelector('.toast-progress');
            if (progress) progress.style.animationPlayState = 'running';
        });
    }
}

export function dismissToast(toast) {
    if (!toast || toast.classList.contains('toast-dismissing')) return;
    // Clear auto-dismiss timer if it exists
    if (toast._dismissTimer) {
        clearTimeout(toast._dismissTimer);
        toast._dismissTimer = null;
    }
    toast.classList.add('toast-dismissing');
    toast.addEventListener('animationend', () => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    });
}

export function openModal(modalElement) {
    if (modalElement) modalElement.classList.add('open');
}

export function closeModal(modalElement) {
    if (modalElement) modalElement.classList.remove('open');
}

