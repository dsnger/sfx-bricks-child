# SFX HTML Copy/Paste Feature

This feature allows you to copy HTML from any source and paste it directly into Bricks Builder, where it will be automatically converted to proper Bricks Builder elements.

## Features

- **Direct HTML Paste**: Copy HTML from any source and paste it directly into Bricks Builder
- **HTML Editor**: Use the Monaco code editor to modify HTML before conversion
- **Element Conversion**: Automatically converts HTML elements to Bricks Builder format
- **CSS Class Preservation**: Maintains and manages CSS classes
- **Custom Attributes**: Preserves custom HTML attributes
- **Image Handling**: Properly handles image elements with src attributes
- **Link Handling**: Converts anchor tags to Bricks link elements
- **SVG Support**: Handles SVG elements as code blocks

## Supported Elements

- Div containers
- Text elements (headings, paragraphs)
- Images with proper src attributes
- Links with href attributes
- SVG elements
- Custom attributes preservation

## Usage

### Direct Paste
1. Copy HTML from any source (website, code editor, etc.)
2. In Bricks Builder, click the "Paste HTML" button in the toolbar
3. The HTML will be automatically converted and pasted as Bricks elements

### Editor Mode
1. Click the "Paste HTML with Editor" button
2. A Monaco code editor will open
3. Paste or edit your HTML in the editor
4. Click "Insert" to convert and paste the HTML

### Context Menu
Right-click in Bricks Builder to access:
- "Paste HTML" - Direct paste from clipboard
- "Paste HTML with Editor" - Open editor for modification

## Settings

The feature can be configured in the WordPress admin under **SFX Theme Settings > HTML Copy/Paste**:

- **Enable HTML Copy/Paste**: Enable/disable the feature
- **Enable HTML Editor**: Enable/disable the Monaco editor mode
- **Preserve Custom Attributes**: Keep custom HTML attributes during conversion
- **Auto Convert Images**: Automatically convert img tags to Bricks image elements
- **Auto Convert Links**: Automatically convert anchor tags to Bricks link elements

## File Structure

```
inc/HtmlCopyPaste/
├── Controller.php          # Main controller
├── AdminPage.php          # Admin page handling
├── Settings.php           # Settings management
├── AssetManager.php       # Asset loading
├── assets/
│   ├── frontend.css      # Frontend styles
│   ├── frontend.js       # Frontend JavaScript
│   ├── admin.css         # Admin styles
│   └── admin.js          # Admin JavaScript
├── index.php             # Security file
└── README.md            # This file
```

## Technical Details

### HTML Conversion Process

1. **Parse HTML**: Uses DOMParser to parse the HTML string
2. **Element Mapping**: Maps HTML elements to Bricks Builder elements:
   - `<div>` → Container elements
   - `<img>` → Image elements
   - `<a>` → Link elements
   - `<h1-h6>` → Heading elements
   - `<svg>` → Code elements
3. **Attribute Preservation**: Preserves CSS classes and custom attributes
4. **Hierarchy Maintenance**: Maintains parent-child relationships
5. **ID Generation**: Generates unique IDs for all elements

### Integration with Bricks Builder

- Adds buttons to the Bricks Builder toolbar
- Adds context menu items
- Integrates with Bricks Builder's paste system
- Uses Bricks Builder's element structure

### Browser Compatibility

- Requires modern browser with Clipboard API support
- Monaco Editor requires ES6+ support
- Works best in Chrome/Edge for clipboard access

## Dependencies

- jQuery (for DOM manipulation)
- Monaco Editor (for code editing)
- Bricks Builder (for integration)

## Security

- All user input is sanitized
- Nonce verification for AJAX requests
- Proper escaping of output
- No direct file access (index.php protection) 