export const state = {
    isAuthed: false,
    userRole: 'admin',
    currentView: 'dashboard',
    subnets: [],
    activeSubnetId: null,
    activeSubnet: null,
    activeIps: [],
    isGridView: true,
    csrfToken: '',
    tagsData: [],
    subnetsTree: []
};

export const dom = {
    authSection: document.getElementById('auth-section'),
    mainSection: document.getElementById('main-section'),
    loginForm: document.getElementById('login-form'),
    passwordInput: document.getElementById('password-input'),
    sidebarNav: document.querySelectorAll('.nav-link'),
    contentArea: document.getElementById('content-area'),
    searchInput: document.getElementById('search-input'),
    logoutBtn: document.getElementById('logout-btn'),
    // Modals
    subnetModal: document.getElementById('subnet-modal'),
    ipModal: document.getElementById('ip-modal'),
    importModal: document.getElementById('import-modal'),
    snmpModal: document.getElementById('snmp-modal'),
    passwordModal: document.getElementById('password-modal'),
    helpModal: document.getElementById('help-modal'),
    tagModal: document.getElementById('tag-modal')
};

window.appState = state;
