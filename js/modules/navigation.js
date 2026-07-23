import { state, dom } from './state.js';
import { escapeHTML, renderTagBadge } from './utils.js';
import { renderDashboard } from './dashboard.js';
import { renderSubnetDetail } from './subnets.js';
import { renderSettings } from './settings.js';
import { renderAbout, renderTags } from './system.js';
import { renderSearch } from './search.js';
import { renderExternalIPs } from './external_ips.js';

export async function loadView(view, params = {}) {
    state.currentView = view;
    if(dom.contentArea) dom.contentArea.innerHTML = '<div style="display:flex;justify-content:center;padding:50px;"><i class="fa fa-spinner fa-spin fa-2x" style="color:var(--color-primary)"></i></div>';
    
    try {
        if (view === 'dashboard') {
            await renderDashboard();
        } else if (view === 'external-ips') {
            await renderExternalIPs();
        } else if (view === 'subnet') {
            state.activeSubnetId = params.id;
            await renderSubnetDetail(params.id);
        } else if (view === 'settings') {
            await renderSettings();
        } else if (view === 'about') {
            await renderAbout();
        } else if (view === 'search') {
            await renderSearch(params.query);
        } else if (view === 'tags') {
            await renderTags();
        }
    } catch (err) {
        console.error('Error rendering view:', err);
        if(dom.contentArea) {
            dom.contentArea.innerHTML = `<div style="padding:40px;text-align:center;color:var(--color-offline);">
                <h3><i class="fa fa-exclamation-triangle"></i> View Render Error</h3>
                <p style="color:var(--text-muted);margin-top:10px;">${escapeHTML(err.message || 'An error occurred while loading this section.')}</p>
                <button class="btn btn-secondary" style="margin-top:15px;" onclick="location.reload()"><i class="fa fa-sync"></i> Refresh Page</button>
            </div>`;
        }
    }
}

export async function fetchTags() {
    try {
        const res = await fetch('api.php?action=get_tags');
        const data = await res.json();
        if (data.status === 'success') state.tagsData = data.tags;
    } catch(e) { console.error(e); }
}

export async function loadSubnetsSidebar() {
    try {
        await fetchTags();
        const res = await fetch('api.php?action=get_subnets');
        const data = await res.json();
        if (data.status === 'success') {
            state.subnets = data.subnets;
            const privateMenu = document.getElementById('sidebar-private-subnets-menu');
            const publicMenu = document.getElementById('sidebar-public-subnets-menu');
            if (!privateMenu && !publicMenu) return;
            
            if (privateMenu) privateMenu.innerHTML = '<ul class="nav-menu" id="private-subnets-tree-root"></ul>';
            if (publicMenu) publicMenu.innerHTML = '<ul class="nav-menu" id="public-subnets-tree-root"></ul>';

            const privateRoot = document.getElementById('private-subnets-tree-root');
            const publicRoot = document.getElementById('public-subnets-tree-root');
            
            const subnetMap = {};
            state.subnets.forEach(s => {
                s.children = [];
                subnetMap[s.id] = s;
            });
            
            const privateTree = [];
            const publicTree = [];
            
            state.subnets.forEach(s => {
                if (s.parent_id && subnetMap[s.parent_id]) {
                    subnetMap[s.parent_id].children.push(s);
                } else {
                    if (s.is_private) {
                        privateTree.push(s);
                    } else {
                        publicTree.push(s);
                    }
                }
            });
            
            state.subnetsTree = [...privateTree, ...publicTree];
            
            function renderTree(nodeList, parentUl) {
                if (!parentUl) return;
                if (nodeList.length === 0) {
                    parentUl.innerHTML = '<li style="padding:6px 16px;color:var(--text-secondary);font-size:0.8rem;opacity:0.6;">No subnets</li>';
                    return;
                }
                nodeList.forEach(s => {
                    const li = document.createElement('li');
                    const hasChildren = s.children && s.children.length > 0;
                    
                    let toggleHtml = hasChildren ? '<span class="folder-toggle"><i class="fa fa-chevron-right"></i></span>' : '<span style="width:15px;display:inline-block;"></span>';
                    
                    li.innerHTML = `
                        <a class="nav-link ${state.activeSubnetId == s.id ? 'active' : ''}" data-subnet-id="${s.id}" style="display:flex;align-items:center;">
                            ${toggleHtml}
                            <span>${escapeHTML(s.name)} <small style="opacity:0.7;">(${escapeHTML(s.subnet)}/${escapeHTML(s.mask)})</small></span>
                        </a>
                    `;
                    
                    const a = li.querySelector('a');
                    
                    if (hasChildren) {
                        const toggleBtn = li.querySelector('.folder-toggle');
                        toggleBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            toggleBtn.classList.toggle('open');
                            const childUl = li.querySelector('.nested-nav');
                            if(childUl) childUl.classList.toggle('open');
                        });
                    }
                    
                    a.addEventListener('click', (e) => {
                        if (e.target.closest('.folder-toggle')) return; 
                        e.preventDefault();
                        document.querySelectorAll('.sidebar .nav-link').forEach(l => l.classList.remove('active'));
                        a.classList.add('active');
                        loadView('subnet', { id: s.id });
                    });
                    
                    parentUl.appendChild(li);
                    
                    if (hasChildren) {
                        const childUl = document.createElement('ul');
                        childUl.className = 'nested-nav';
                        
                        const checkActive = (nodes) => {
                            for(let n of nodes) {
                                if(n.id == state.activeSubnetId) return true;
                                if(n.children && checkActive(n.children)) return true;
                            }
                            return false;
                        };
                        if (checkActive(s.children)) {
                            childUl.classList.add('open');
                            li.querySelector('.folder-toggle').classList.add('open');
                        }
                        
                        li.appendChild(childUl);
                        renderTree(s.children, childUl);
                    }
                });
            }
            
            if (privateRoot) renderTree(privateTree, privateRoot);
            if (publicRoot) renderTree(publicTree, publicRoot);
        }
    } catch (err) {
        console.error(err);
    }
}

export function setupNavigationListeners() {
    if(dom.sidebarNav) {
        dom.sidebarNav.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const target = link.dataset.view;
                if (target) {
                    document.querySelectorAll('.sidebar .nav-link').forEach(l => l.classList.remove('active'));
                    link.classList.add('active');
                    state.activeSubnetId = null;
                    loadView(target);
                }
            });
        });
    }

    const brandLogoLink = document.querySelector('.brand-logo');
    if (brandLogoLink) {
        brandLogoLink.addEventListener('click', (e) => {
            e.preventDefault();
            document.querySelectorAll('.sidebar .nav-link').forEach(l => l.classList.remove('active'));
            state.activeSubnetId = null;
            loadView('dashboard');
        });
    }

    document.addEventListener('click', (e) => {
        const badge = e.target.closest('.tag-badge-clickable');
        if (badge) {
            e.preventDefault();
            const tagName = badge.getAttribute('data-tag-name');
            if (tagName) {
                document.querySelectorAll('.sidebar .nav-link').forEach(l => l.classList.remove('active'));
                state.activeSubnetId = null;
                loadView('search', { query: tagName });
            }
        }
    });
}
