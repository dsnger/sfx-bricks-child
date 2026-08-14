// Tab navigation for WP Optimizer admin page
// Fix: set display to 'block' and prevent default button behavior

document.addEventListener('DOMContentLoaded', function () {
    const tabBtns = document.querySelectorAll('#sfx-wpoptimizer-tabs .sfx-tab-btn');
    const tabContents = document.querySelectorAll('#sfx-wpoptimizer-tabs .sfx-tab-content');
    if (!tabBtns.length) return;

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault(); // Prevent form submission or default button behavior
            // Remove active from all buttons
            tabBtns.forEach(b => b.classList.remove('active'));
            // Hide all tab contents
            tabContents.forEach(tc => tc.style.display = 'none');
            // Activate this button
            btn.classList.add('active');
            // Show the corresponding tab content
            const tabId = btn.getAttribute('data-tab');
            const tabContent = document.getElementById(tabId);
            if (tabContent) {
                tabContent.style.display = 'block';
            }
        });
    });
});
