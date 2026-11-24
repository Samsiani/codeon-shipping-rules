(function (wp) {
    const { createElement } = wp.element;
    const { __ } = wp.i18n;
    wp.blocks.registerBlockType('codeon-shipping-rules/progress', {
        edit: function () {
            return createElement('p', wp.blockEditor.useBlockProps(), __('Free shipping progress appears when a cart has an eligible delivery method.', 'codeon-shipping-rules'));
        },
        save: function () { return null; }
    });
})(window.wp);
