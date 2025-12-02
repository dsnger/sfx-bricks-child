/**
 * Custom Dashboard Scripts
 *
 * @package SFX_Bricks_Child_Theme
 */

(function() {
    'use strict';

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
     * Initialize stat counters
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
     * Initialize dashboard
     */
    function initDashboard() {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                initStatCounters();
            });
        } else {
            initStatCounters();
        }
    }

    // Initialize
    initDashboard();
})();

