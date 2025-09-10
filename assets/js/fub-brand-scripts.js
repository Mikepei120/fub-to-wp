/**
 * FUB Integration Plugin - Brand Scripts
 * Applies brand styling and animations
 */

(function($) {
    'use strict';

    // Add brand class to body when plugin admin page loads
    $(document).ready(function() {
        // Check if we're on a FUB plugin page
        if ($('.fub-setup-wizard').length || $('.fub-stats').length || $('.fub-tag-selector').length) {
            $('body').addClass('fub-admin-page');
        }

        // Add slide-in animation to setup steps
        $('.fub-setup-step').addClass('fub-slide-in');

        // Add hover effects to stat boxes
        $('.fub-stat-box').hover(
            function() {
                $(this).addClass('fub-hover-effect');
            },
            function() {
                $(this).removeClass('fub-hover-effect');
            }
        );

        // Enhanced tag chip interactions - FIXED AND IMPROVED
        $(document).on('click', '.fub-tag-chip', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var $chip = $(this);
            var $checkbox = $chip.find('input[type="checkbox"]');
            var isCurrentlyChecked = $checkbox.prop('checked');
            
            console.log('Tag chip clicked:', $chip.text().trim());
            console.log('Current state:', isCurrentlyChecked);
            
            // Toggle checkbox state
            $checkbox.prop('checked', !isCurrentlyChecked);
            
            // Toggle visual state
            if (!isCurrentlyChecked) {
                $chip.addClass('selected');
                console.log('Tag selected');
            } else {
                $chip.removeClass('selected');
                console.log('Tag deselected');
            }
        });
        
        // Also handle checkbox clicks directly
        $(document).on('change', '.fub-tag-chip input[type="checkbox"]', function() {
            var $checkbox = $(this);
            var $chip = $checkbox.closest('.fub-tag-chip');
            
            if ($checkbox.prop('checked')) {
                $chip.addClass('selected');
            } else {
                $chip.removeClass('selected');
            }
        });

        // Button animations (similar to web design hover effects)
        $('.button-primary').hover(
            function() {
                $(this).addClass('fub-button-hover');
            },
            function() {
                $(this).removeClass('fub-button-hover');
            }
        );

        // Progress bar animations
        animateProgressBar();

        // Smooth scrolling for long forms
        $('html').css('scroll-behavior', 'smooth');
    });

    // Function to animate progress bar steps
    function animateProgressBar() {
        $('.fub-progress-step').each(function(index) {
            var $step = $(this);
            setTimeout(function() {
                $step.addClass('fub-animate-step');
            }, index * 100);
        });
    }

    // Add custom CSS animations dynamically
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            /* Ripple effect removed */

            .fub-animate-step {
                /* Animaciones de bounce eliminadas */
            }

            /* Keyframes de bounce eliminados */

            .fub-hover-effect {
                /* Efectos de movimiento eliminados */
            }

            .fub-button-hover {
                /* Efectos de movimiento eliminados */
            }
        `)
        .appendTo('head');

})(jQuery);