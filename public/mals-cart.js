/**
 * Add to cart for the Mals hosted cart. A theme marks the link with data-mals-add (and the product name in
 * data-title) inside the form that posts to Mals; this finds the form, says thank you and submits it.
 * It is an external script, not an inline handler, because the site's security policy blocks inline handlers.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const link = event.target.closest('[data-mals-add]');
        if (!link) return;

        event.preventDefault();
        const form = link.closest('form');
        if (!form) return;

        const quantityField = form.querySelector('[name="qty"]');
        const quantity = quantityField ? Math.max(1, Number(quantityField.value) || 1) : 1;
        const title = link.dataset.title || 'The item';

        window.alert('Thank you.\n\n' + quantity + ' × ' + title + ' ha' + (quantity > 1 ? 've' : 's') + ' been added to your cart.\n\nPlease click OK to continue.');
        form.submit();
    });
})();
