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
        const html = document.documentElement;
        
        if (animate) {
            body.classList.add('sfx-sidebar-transitioning');
        }

        if (state === 'collapsed') {
            body.classList.add('sfx-sidebar-collapsed');
            html.classList.add('sfx-sidebar-collapsed');
        } else {
            body.classList.remove('sfx-sidebar-collapsed');
            html.classList.remove('sfx-sidebar-collapsed');
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
     * Initialize command shortcut click handler
     * Triggers Ctrl+K / Cmd+K when clicking on the shortcut button
     */
    function initCommandShortcut() {
        document.addEventListener('click', function(e) {
            const shortcutBtn = e.target.closest('.sfx-cmd-shortcut');
            if (!shortcutBtn) return;

            e.preventDefault();

            // Create and dispatch keyboard event for Cmd+K / Ctrl+K
            const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
            const keyboardEvent = new KeyboardEvent('keydown', {
                key: 'k',
                code: 'KeyK',
                keyCode: 75,
                which: 75,
                ctrlKey: !isMac,
                metaKey: isMac,
                bubbles: true,
                cancelable: true
            });

            document.dispatchEvent(keyboardEvent);
        });
    }

    // ========================================
    // Custom Confirm Dialog
    // ========================================

    /**
     * Show a custom confirm dialog with Yes/No buttons
     * @param {string} message - The message to display
     * @param {string} yesText - Text for the Yes button
     * @param {string} noText - Text for the No button
     * @param {function} callback - Callback with true/false result
     */
    function showConfirmDialog(message, yesText, noText, callback) {
        // Create overlay
        var overlay = document.createElement('div');
        overlay.className = 'sfx-confirm-overlay';
        
        // Create dialog
        var dialog = document.createElement('div');
        dialog.className = 'sfx-confirm-dialog';
        dialog.innerHTML = 
            '<p class="sfx-confirm-message">' + message + '</p>' +
            '<div class="sfx-confirm-buttons">' +
                '<button type="button" class="sfx-confirm-no">' + noText + '</button>' +
                '<button type="button" class="sfx-confirm-yes">' + yesText + '</button>' +
            '</div>';
        
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
        
        // Focus the Yes button
        dialog.querySelector('.sfx-confirm-yes').focus();
        
        // Handle button clicks
        function handleClick(result) {
            document.body.removeChild(overlay);
            callback(result);
        }
        
        dialog.querySelector('.sfx-confirm-yes').addEventListener('click', function() {
            handleClick(true);
        });
        
        dialog.querySelector('.sfx-confirm-no').addEventListener('click', function() {
            handleClick(false);
        });
        
        // Handle keyboard
        overlay.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                handleClick(false);
            } else if (e.key === 'Enter') {
                handleClick(true);
            }
        });
        
        // Close on overlay click
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                handleClick(false);
            }
        });
    }

    // ========================================
    // User Note Editor Functionality (Pell WYSIWYG)
    // ========================================

    /**
     * Initialize user note editor functionality with Pell WYSIWYG
     */
    function initUserNoteEditor() {
        const noteSection = document.querySelector('.sfx-note-editable');
        if (!noteSection) return;

        const editBtn = noteSection.querySelector('.sfx-note-edit-btn');
        const cancelBtn = noteSection.querySelector('.sfx-note-cancel-btn');
        const saveBtn = noteSection.querySelector('.sfx-note-save-btn');
        const viewDiv = noteSection.querySelector('.sfx-note-view');
        const editorDiv = noteSection.querySelector('.sfx-note-editor');
        const pellContainer = noteSection.querySelector('.sfx-note-pell-container');
        const nonceField = noteSection.querySelector('#sfx_user_note_nonce');

        if (!editBtn || !viewDiv || !editorDiv || !pellContainer) return;

        // Get strings from localized data
        const strings = (window.sfxDashboard && window.sfxDashboard.strings) || {
            saving: 'Saving...',
            saved: 'Saved!',
            error: 'Error saving note.',
            confirmReset: 'Reset to the default note? Your personal note will be deleted.',
            enterLink: 'Enter the link URL:',
            placeholder: 'Click edit to add your personal note...'
        };

        // Pell editor instance
        let pellEditor = null;
        let editorContent = pellContainer.getAttribute('data-initial-content') || '';
        let isCodeMode = false;
        let codeTextarea = null;

        /**
         * Initialize Pell editor
         */
        function initPellEditor() {
            if (pellEditor || !window.pell) return;

            // SVG icons for the editor toolbar
            var icons = {
                bold: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"></path><path d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"></path></svg>',
                italic: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="4" x2="10" y2="4"></line><line x1="14" y1="20" x2="5" y2="20"></line><line x1="15" y1="4" x2="9" y2="20"></line></svg>',
                underline: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v6a6 6 0 0 0 12 0V4"></path><line x1="4" y1="20" x2="20" y2="20"></line></svg>',
                link: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>',
                olist: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="10" y1="6" x2="21" y2="6"></line><line x1="10" y1="12" x2="21" y2="12"></line><line x1="10" y1="18" x2="21" y2="18"></line><path d="M4 6h1v4"></path><path d="M4 10h2"></path><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"></path></svg>',
                ulist: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="9" y1="6" x2="20" y2="6"></line><line x1="9" y1="12" x2="20" y2="12"></line><line x1="9" y1="18" x2="20" y2="18"></line><circle cx="4" cy="6" r="1" fill="currentColor"></circle><circle cx="4" cy="12" r="1" fill="currentColor"></circle><circle cx="4" cy="18" r="1" fill="currentColor"></circle></svg>',
                heading: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16"></path><path d="M18 4v16"></path><path d="M6 12h12"></path></svg>',
                paragraph: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 4v16"></path><path d="M17 4v16"></path><path d="M19 4H9.5a4.5 4.5 0 0 0 0 9H13"></path></svg>',
                code: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>'
            };

            pellEditor = window.pell.init({
                element: pellContainer,
                onChange: function(html) {
                    editorContent = html;
                },
                defaultParagraphSeparator: 'p',
                styleWithCSS: false,
                actions: [
                    {
                        name: 'bold',
                        icon: icons.bold,
                        title: 'Bold',
                        state: function() { return document.queryCommandState('bold'); },
                        result: function() { return window.pell.exec('bold'); }
                    },
                    {
                        name: 'italic',
                        icon: icons.italic,
                        title: 'Italic',
                        state: function() { return document.queryCommandState('italic'); },
                        result: function() { return window.pell.exec('italic'); }
                    },
                    {
                        name: 'underline',
                        icon: icons.underline,
                        title: 'Underline',
                        state: function() { return document.queryCommandState('underline'); },
                        result: function() { return window.pell.exec('underline'); }
                    },
                    {
                        name: 'link',
                        icon: icons.link,
                        title: 'Link',
                        result: function() {
                            var url = window.prompt(strings.enterLink);
                            if (url) {
                                // Create link first
                                window.pell.exec('createLink', url);
                                
                                // Show custom dialog for target option
                                showConfirmDialog(
                                    strings.openNewTab || 'Open link in new tab?',
                                    strings.yes || 'Ja',
                                    strings.no || 'Nein',
                                    function(confirmed) {
                                        if (confirmed) {
                                            // Find the link we just created and add target
                                            var links = pellEditor.content.querySelectorAll('a[href="' + url + '"]');
                                            links.forEach(function(link) {
                                                if (!link.hasAttribute('target')) {
                                                    link.setAttribute('target', '_blank');
                                                    link.setAttribute('rel', 'noopener noreferrer');
                                                }
                                            });
                                        }
                                        pellEditor.content.focus();
                                    }
                                );
                            }
                        }
                    },
                    {
                        name: 'olist',
                        icon: icons.olist,
                        title: 'Ordered List',
                        result: function() { return window.pell.exec('insertOrderedList'); }
                    },
                    {
                        name: 'ulist',
                        icon: icons.ulist,
                        title: 'Unordered List',
                        result: function() { return window.pell.exec('insertUnorderedList'); }
                    },
                    {
                        name: 'heading2',
                        icon: icons.heading,
                        title: 'Heading',
                        result: function() { return window.pell.exec('formatBlock', '<h2>'); }
                    },
                    {
                        name: 'paragraph',
                        icon: icons.paragraph,
                        title: 'Paragraph',
                        result: function() { return window.pell.exec('formatBlock', '<p>'); }
                    }
                ]
            });

            // Set initial content
            if (editorContent) {
                pellEditor.content.innerHTML = editorContent;
            }
            
            // Add code/visual toggle button
            var actionbar = pellContainer.querySelector('.pell-actionbar');
            if (actionbar) {
                // Add separator
                var separator = document.createElement('span');
                separator.className = 'pell-separator';
                actionbar.appendChild(separator);
                
                // Add code toggle button
                var codeBtn = document.createElement('button');
                codeBtn.type = 'button';
                codeBtn.className = 'pell-button pell-code-toggle';
                codeBtn.innerHTML = icons.code;
                codeBtn.title = strings.codeView || 'HTML';
                actionbar.appendChild(codeBtn);
                
                // Create code textarea (hidden initially)
                codeTextarea = document.createElement('textarea');
                codeTextarea.className = 'pell-code-editor';
                codeTextarea.style.display = 'none';
                pellContainer.appendChild(codeTextarea);
                
                // Toggle handler
                codeBtn.addEventListener('click', function() {
                    isCodeMode = !isCodeMode;
                    
                    if (isCodeMode) {
                        // Switch to code mode
                        codeTextarea.value = pellEditor.content.innerHTML;
                        pellEditor.content.style.display = 'none';
                        codeTextarea.style.display = 'block';
                        codeBtn.classList.add('pell-button-selected');
                        codeTextarea.focus();
                    } else {
                        // Switch to visual mode
                        pellEditor.content.innerHTML = codeTextarea.value;
                        editorContent = codeTextarea.value;
                        codeTextarea.style.display = 'none';
                        pellEditor.content.style.display = 'block';
                        codeBtn.classList.remove('pell-button-selected');
                        pellEditor.content.focus();
                    }
                });
            }
        }

        /**
         * Enter edit mode
         */
        function enterEditMode() {
            viewDiv.style.display = 'none';
            editorDiv.style.display = 'block';
            noteSection.classList.add('sfx-note-editing');
            
            // Initialize Pell on first edit
            initPellEditor();
            
            // Focus the editor
            if (pellEditor && pellEditor.content) {
                pellEditor.content.focus();
            }
        }

        /**
         * Exit edit mode without saving
         */
        function exitEditMode() {
            editorDiv.style.display = 'none';
            viewDiv.style.display = 'block';
            noteSection.classList.remove('sfx-note-editing');
        }

        /**
         * Get current editor content
         */
        function getEditorContent() {
            // If in code mode, get from textarea
            if (isCodeMode && codeTextarea) {
                var codeContent = codeTextarea.value;
                if (codeContent === '<p><br></p>' || codeContent === '<br>') {
                    return '';
                }
                return codeContent;
            }
            
            if (pellEditor && pellEditor.content) {
                // Clean up empty content
                var content = pellEditor.content.innerHTML;
                if (content === '<p><br></p>' || content === '<br>') {
                    return '';
                }
                return content;
            }
            return editorContent;
        }

        /**
         * Save the note via AJAX
         * @param {string} content - The note content to save
         */
        function saveNote(content) {
            const ajaxUrl = (window.sfxDashboard && window.sfxDashboard.ajaxUrl) || '/wp-admin/admin-ajax.php';
            const nonce = nonceField ? nonceField.value : '';

            // Show saving state
            saveBtn.disabled = true;
            saveBtn.textContent = strings.saving;

            const formData = new FormData();
            formData.append('action', 'sfx_save_user_note');
            formData.append('nonce', nonce);
            formData.append('note_content', content);

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    // Update the view content
                    if (content.trim() === '' || content === '<p><br></p>') {
                        viewDiv.innerHTML = '<p class="sfx-note-placeholder">' + strings.placeholder + '</p>';
                        if (pellEditor && pellEditor.content) {
                            pellEditor.content.innerHTML = '';
                        }
                        editorContent = '';
                    } else {
                        viewDiv.innerHTML = data.data.content;
                        editorContent = data.data.content;
                    }

                    // Show success feedback
                    saveBtn.textContent = strings.saved;
                    setTimeout(function() {
                        exitEditMode();
                        saveBtn.disabled = false;
                        saveBtn.textContent = strings.save || 'Save';
                    }, 500);
                } else {
                    // Show error
                    alert(data.data && data.data.message || strings.error);
                    saveBtn.disabled = false;
                    saveBtn.textContent = strings.save || 'Save';
                }
            })
            .catch(function(error) {
                console.error('Save note error:', error);
                alert(strings.error);
                saveBtn.disabled = false;
                saveBtn.textContent = strings.save || 'Save';
            });
        }

        // Event listeners
        editBtn.addEventListener('click', function(e) {
            e.preventDefault();
            enterEditMode();
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function(e) {
                e.preventDefault();
                exitEditMode();
            });
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', function(e) {
                e.preventDefault();
                saveNote(getEditorContent());
            });
        }

        // Handle keyboard shortcuts in the editor container
        editorDiv.addEventListener('keydown', function(e) {
            // Save on Ctrl/Cmd + Enter
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                saveNote(getEditorContent());
            }
            // Cancel on Escape
            if (e.key === 'Escape') {
                e.preventDefault();
                exitEditMode();
            }
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
        initCommandShortcut();
        initUserNoteEditor();
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
