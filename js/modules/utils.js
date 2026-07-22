export function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function(match) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[match];
    });
}

export function renderTagBadge(name, color = '#3b82f6') {
    if (!name) return '-';
    return `<span class="badge tag-badge-clickable" data-tag-name="${escapeHTML(name)}" style="background-color: ${color}20; color: ${color}; border: 1px solid ${color}40; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem; font-weight: 500; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; margin: 2px 2px 2px 0; white-space: nowrap;" title="Click to view subnets with tag: ${escapeHTML(name)}"><i class="fa fa-tag" style="font-size:0.75rem;"></i> ${escapeHTML(name)}</span>`;
}

export function renderSubnetTagBadges(subnet) {
    if (!subnet) return '-';
    if (subnet.all_tags && subnet.all_tags.length > 0) {
        return `<div style="display:inline-flex;flex-wrap:wrap;gap:4px;align-items:center;">` + subnet.all_tags.map(t => renderTagBadge(t.name, t.color)).join('') + `</div>`;
    }
    if (subnet.tag_name) {
        return renderTagBadge(subnet.tag_name, subnet.tag_color);
    }
    return '-';
}
