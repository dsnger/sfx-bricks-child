/**
 * Custom Dashboard Scripts
 * 
 * Includes:
 * - Stat counter animations
 * - Theme mode switching (light/dark/system)
 * - Sidebar toggle (offcanvas)
 * - Local storage persistence for user preferences
 *
 * @package SFX_Bricks_Child_Theme
 */

(function() {
    'use strict';

    // Storage keys for preferences
    const THEME_STORAGE_KEY = 'sfx-dashboard-theme';
    const SIDEBAR_STORAGE_KEY = 'sfx-dashboard-sidebar';

    /**
     * Get the dashboard container element
     * @returns {HTMLElement|null}
     */
    function getDashboardContainer() {
        return document.querySelector('.sfx-dashboard-container');
    }

    /**
     * Get the current theme from various sources
     * Priority: localStorage > data-default-theme attribute > 'light'
     * @returns {string} 'light', 'dark', or 'system'
     */
    function getCurrentTheme() {
        // Check localStorage first (user preference)
        const stored = localStorage.getItem(THEME_STORAGE_KEY);
        if (stored && ['light', 'dark', 'system'].includes(stored)) {
            return stored;
        }

        // Fall back to default from server
        const container = getDashboardContainer();
        if (container) {
            const defaultTheme = container.getAttribute('data-default-theme');
            if (defaultTheme && ['light', 'dark', 'system'].includes(defaultTheme)) {
                return defaultTheme;
            }
        }

        return 'light';
    }

    /**
     * Apply theme to the dashboard container
     * @param {string} theme - 'light', 'dark', or 'system'
     * @param {boolean} animate - Whether to animate the transition
     */
    function applyTheme(theme, animate = true) {
        const container = getDashboardContainer();
        if (!container) return;

        // Add transitioning class for smooth color changes
        if (animate) {
            container.classList.add('sfx-theme-transitioning');
        }

        // Set the theme attribute
        container.setAttribute('data-theme', theme);

        // Update ARIA label on toggle button
        const toggleBtn = document.getElementById('sfx-theme-toggle');
        if (toggleBtn) {
            const labels = {
                light: 'Switch to dark mode',
                dark: 'Switch to system mode',
                system: 'Switch to light mode'
            };
            toggleBtn.setAttribute('aria-label', labels[theme] || 'Toggle color mode');
            toggleBtn.setAttribute('title', labels[theme] || 'Toggle color mode');
        }

        // Remove transitioning class after animation completes
        if (animate) {
            setTimeout(function() {
                container.classList.remove('sfx-theme-transitioning');
            }, 300);
        }
    }

    /**
     * Save theme preference to localStorage
     * @param {string} theme - 'light', 'dark', or 'system'
     */
    function saveThemePreference(theme) {
        try {
            localStorage.setItem(THEME_STORAGE_KEY, theme);
        } catch (e) {
            // localStorage might be disabled or full
            console.warn('Could not save theme preference:', e);
        }
    }

    /**
     * Cycle through themes: light -> dark -> system -> light
     * @returns {string} The new theme
     */
    function cycleTheme() {
        const currentTheme = getCurrentTheme();
        const themes = ['light', 'dark', 'system'];
        const currentIndex = themes.indexOf(currentTheme);
        const nextIndex = (currentIndex + 1) % themes.length;
        return themes[nextIndex];
    }

    /**
     * Initialize theme toggle functionality
     */
    function initThemeToggle() {
        const toggleBtn = document.getElementById('sfx-theme-toggle');
        if (!toggleBtn) return;

        // Apply initial theme without animation
        const initialTheme = getCurrentTheme();
        applyTheme(initialTheme, false);

        // Handle toggle click
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const newTheme = cycleTheme();
            applyTheme(newTheme, true);
            saveThemePreference(newTheme);
        });

        // Handle keyboard accessibility
        toggleBtn.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleBtn.click();
            }
        });
    }

    /**
     * Handle system preference changes (for 'system' mode)
     */
    function initSystemPreferenceListener() {
        if (!window.matchMedia) return;

        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        
        // Listen for system preference changes
        mediaQuery.addEventListener('change', function(e) {
            const container = getDashboardContainer();
            if (container && container.getAttribute('data-theme') === 'system') {
                // Re-apply system theme to trigger CSS update
                applyTheme('system', true);
            }
        });
    }

    // ========================================
    // Sidebar Toggle Functionality
    // ========================================

    /**
     * Get the current sidebar state from various sources
     * Priority: localStorage > data-sidebar-default attribute > 'visible'
     * @returns {string} 'visible' or 'collapsed'
     */
    function getSidebarState() {
        // Check localStorage first (user preference)
        const stored = localStorage.getItem(SIDEBAR_STORAGE_KEY);
        if (stored && ['visible', 'collapsed'].includes(stored)) {
            return stored;
        }

        // Fall back to default from server
        const container = getDashboardContainer();
        if (container) {
            const defaultState = container.getAttribute('data-sidebar-default');
            if (defaultState && ['visible', 'collapsed'].includes(defaultState)) {
                return defaultState;
            }
        }

        return 'visible';
    }

    /**
     * Apply sidebar state to the page
     * @param {string} state - 'visible' or 'collapsed'
     * @param {boolean} animate - Whether to animate the transition
     */
    function applySidebarState(state, animate = true) {
        const body = document.body;
        
        if (animate) {
            body.classList.add('sfx-sidebar-transitioning');
        }

        if (state === 'collapsed') {
            body.classList.add('sfx-sidebar-collapsed');
        } else {
            body.classList.remove('sfx-sidebar-collapsed');
        }

        // Update ARIA label on toggle button
        const toggleBtn = document.getElementById('sfx-sidebar-toggle');
        if (toggleBtn) {
            const label = state === 'collapsed' ? 'Show admin sidebar' : 'Hide admin sidebar';
            toggleBtn.setAttribute('aria-label', label);
            toggleBtn.setAttribute('title', label);
            toggleBtn.setAttribute('aria-expanded', state === 'visible' ? 'true' : 'false');
        }

        // Remove transitioning class after animation completes
        if (animate) {
            setTimeout(function() {
                body.classList.remove('sfx-sidebar-transitioning');
            }, 300);
        }
    }

    /**
     * Save sidebar preference to localStorage
     * @param {string} state - 'visible' or 'collapsed'
     */
    function saveSidebarPreference(state) {
        try {
            localStorage.setItem(SIDEBAR_STORAGE_KEY, state);
        } catch (e) {
            // localStorage might be disabled or full
            console.warn('Could not save sidebar preference:', e);
        }
    }

    /**
     * Toggle sidebar state
     * @returns {string} The new state
     */
    function toggleSidebar() {
        const currentState = getSidebarState();
        return currentState === 'visible' ? 'collapsed' : 'visible';
    }

    /**
     * Initialize sidebar toggle functionality
     */
    function initSidebarToggle() {
        const toggleBtn = document.getElementById('sfx-sidebar-toggle');
        if (!toggleBtn) return;

        // Apply initial state without animation
        const initialState = getSidebarState();
        applySidebarState(initialState, false);

        // Handle toggle click
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const newState = toggleSidebar();
            applySidebarState(newState, true);
            saveSidebarPreference(newState);
        });

        // Handle keyboard accessibility
        toggleBtn.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleBtn.click();
            }
        });
    }

    /**
     * Animate counter from 0 to target value
     * @param {HTMLElement} element - The element to animate
     * @param {number} target - The target number
     * @param {number} duration - Animation duration in milliseconds
     */
    function animateCounter(element, target, duration) {
        const start = 0;
        const increment = target / (duration / 16); // 60fps
        let current = start;

        const timer = setInterval(function() {
            current += increment;
            if (current >= target) {
                element.textContent = target.toString();
                clearInterval(timer);
            } else {
                element.textContent = Math.floor(current).toString();
            }
        }, 16);
    }

    /**
     * Initialize stat counters with intersection observer
     */
    function initStatCounters() {
        const statValues = document.querySelectorAll('.sfx-stat-value[data-target]');
        
        if (statValues.length === 0) {
            return;
        }

        // Use Intersection Observer to trigger animation when stats come into view
        const observer = new IntersectionObserver(
            function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        const target = parseInt(entry.target.getAttribute('data-target'), 10);
                        if (!isNaN(target)) {
                            animateCounter(entry.target, target, 1500);
                        }
                        observer.unobserve(entry.target);
                    }
                });
            },
            {
                threshold: 0.5
            }
        );

        statValues.forEach(function(element) {
            observer.observe(element);
        });
    }

    /**
     * Initialize all dashboard functionality
     */
    function initDashboard() {
        initThemeToggle();
        initSystemPreferenceListener();
        initSidebarToggle();
        initStatCounters();
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDashboard);
    } else {
        initDashboard();
    }

    // Expose theme API for external use if needed
    window.sfxDashboardTheme = {
        get: getCurrentTheme,
        set: function(theme) {
            if (['light', 'dark', 'system'].includes(theme)) {
                applyTheme(theme, true);
                saveThemePreference(theme);
            }
        },
        cycle: function() {
            const newTheme = cycleTheme();
            applyTheme(newTheme, true);
            saveThemePreference(newTheme);
            return newTheme;
        }
    };

    // Expose sidebar API for external use if needed
    window.sfxDashboardSidebar = {
        get: getSidebarState,
        set: function(state) {
            if (['visible', 'collapsed'].includes(state)) {
                applySidebarState(state, true);
                saveSidebarPreference(state);
            }
        },
        toggle: function() {
            const newState = toggleSidebar();
            applySidebarState(newState, true);
            saveSidebarPreference(newState);
            return newState;
        }
    };
})();
