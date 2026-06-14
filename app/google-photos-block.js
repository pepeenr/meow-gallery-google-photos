(function () {
  var registerBlockType = wp.blocks.registerBlockType;
  var createElement = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var __ = wp.i18n.__;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var useBlockProps = wp.blockEditor.useBlockProps;
  var PanelBody = wp.components.PanelBody;
  var TextControl = wp.components.TextControl;
  var SelectControl = wp.components.SelectControl;

  registerBlockType('meow-gallery/google-photos', {
    apiVersion: 3,
    title: __('Meow Gallery: Google Photos', 'meow-gallery'),
    category: 'media',
    icon: 'format-gallery',
    description: __('Display all photos from a public Google Photos album using a Meow Gallery layout.', 'meow-gallery'),
    supports: { html: false },

    attributes: {
      albumUrl: { type: 'string', default: '' },
      cacheInterval: { type: 'integer', default: 15 },
      layout: { type: 'string', default: '' }
    },

    edit: function (props) {
      var attributes = props.attributes;
      var setAttributes = props.setAttributes;

      var message = attributes.albumUrl
        ? __('Google Photos album (Meow Gallery) — photos will appear here on the frontend.', 'meow-gallery')
        : __('Google Photos: set an Album URL in the block settings panel (⚙).', 'meow-gallery');

      return createElement(
        Fragment,
        null,
        createElement(
          InspectorControls,
          null,
          createElement(
            PanelBody,
            { title: __('Google Photos Settings', 'meow-gallery'), initialOpen: true },
            createElement(TextControl, {
              label: __('Album URL', 'meow-gallery'),
              value: attributes.albumUrl,
              onChange: function (val) { setAttributes({ albumUrl: val }); },
              type: 'url',
              help: __('URL of a public Google Photos album (photos.app.goo.gl or photos.google.com).', 'meow-gallery')
            }),
            createElement(SelectControl, {
              label: __('Layout', 'meow-gallery'),
              value: attributes.layout,
              options: [
                { label: __('Default (from settings)', 'meow-gallery'), value: '' },
                { label: 'Tiles', value: 'tiles' },
                { label: 'Masonry', value: 'masonry' },
                { label: 'Justified', value: 'justified' },
                { label: 'Square', value: 'square' },
                { label: 'Cascade', value: 'cascade' },
                { label: 'Horizontal', value: 'horizontal' }
              ],
              onChange: function (val) { setAttributes({ layout: val }); }
            }),
            createElement(TextControl, {
              label: __('Cache Interval (minutes)', 'meow-gallery'),
              value: attributes.cacheInterval,
              onChange: function (val) { setAttributes({ cacheInterval: parseInt(val, 10) || 0 }); },
              type: 'number',
              min: 0,
              help: __('How long to cache the album photos. Set to 0 to disable caching.', 'meow-gallery')
            })
          )
        ),
        createElement(
          'div',
          useBlockProps({
            style: {
              padding: '1em',
              background: '#f0f0f1',
              border: '1px dashed #999',
              borderRadius: '2px',
              textAlign: 'center'
            }
          }),
          createElement('p', { style: { margin: 0 } }, message)
        )
      );
    },

    save: function () {
      return null; // Server-side rendered via render_callback.
    }
  });
})();
