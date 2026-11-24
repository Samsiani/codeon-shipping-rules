CodeOn Shipping Rules translations

English is the source language. The plugin ships Georgian (ka_GE) for
PHP messages, the dashboard workspace, and the block editor. WooCommerce
supplies its own translations for the surrounding WordPress and checkout
screens.

From the plugin directory, refresh the template and merge the Georgian
translation with WP-CLI and GNU gettext.

  wp i18n make-pot . languages/codeon-shipping-rules.pot --exclude=framework,vendor,tests,tools,languages
  msgmerge --update --backup=none languages/codeon-shipping-rules-ka_GE.po languages/codeon-shipping-rules.pot

Translate the new entries in the PO file and check format placeholders.

  msgfmt --check --check-format -o languages/codeon-shipping-rules-ka_GE.mo languages/codeon-shipping-rules-ka_GE.po

Refresh the script translations with WP-CLI. Its hashed filenames work
alongside WordPress language packs. The handle-specific JSON files in this
directory take precedence, so update them from the extracted catalogs.

  wp i18n make-json languages/codeon-shipping-rules-ka_GE.po languages --no-purge

Copy the JSON whose source is assets/admin/workspace.js to
codeon-shipping-rules-ka_GE-codeon-shipping-rules-admin.json. Copy the JSON
whose source is assets/blocks/editor.js to
codeon-shipping-rules-ka_GE-codeon-shipping-progress-editor.json.

Check the dashboard in the Georgian user locale. In the block editor,
insert the Free shipping progress block and verify its label, description,
and placeholders. Check the notice for a package with an eligible rate in
both classic and block checkout. Live method labels and entered content
follow the store's translation-provider settings.
