=== CF7 to HighLevel Pro ===
Contributors: thepluginfactory
Tags: contact form 7, highlevel, crm, field mapping, pro, gohighlevel
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pro add-on for CF7 to HighLevel - per-form field mapping with full HighLevel contact field support.

== Description ==

CF7 to HighLevel Pro extends the free CF7 to HighLevel plugin with per-form field mapping, allowing you to use different field configurations for each Contact Form 7 form.

**Requires:** The free [CF7 to HighLevel](https://github.com/thepluginfactory/CF7-To-GHL) plugin must be installed and activated.

**Pro Features:**

* Per-form field mapping - each CF7 form can have its own unique field mapping
* Support for ALL HighLevel standard contact fields (name, email, phone, company, address, etc.)
* Unlimited custom field mappings per form
* Auto-detect CF7 fields from your form template
* Dynamic add/remove mapping rows in the CF7 editor
* Falls back to global mapping when no per-form mapping is set

**Supported HighLevel Fields:**

* **Name:** Full Name (auto-split), First Name, Last Name
* **Contact:** Email, Phone, Company Name, Website
* **Address:** Address, City, State, Postal Code, Country
* **Other:** Lead Source, Tags, Gender, Date of Birth
* **Custom Fields:** Unlimited custom field key/value pairs

== Installation ==

1. Ensure the free CF7 to HighLevel plugin is installed and activated
2. Upload the `cf7-to-highlevel-pro` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Edit any CF7 form and go to the HighLevel tab to set up per-form field mappings

== Configuration ==

**Setting Up Per-Form Field Mapping:**

1. Edit any Contact Form 7 form
2. Click the "HighLevel" tab
3. Scroll down to "Field Mapping (Pro)"
4. Click "Auto-Detect CF7 Fields" to see available fields from your form template
5. Click a detected field name or use "Add Mapping Row" to add rows
6. For each row, enter the CF7 field name and select the corresponding HighLevel field
7. For custom fields, select "Custom Field (enter key)" and enter your GHL custom field key
8. Save the form

**Important:** Per-form mappings override the global field mapping set in Contact > HighLevel. Forms without per-form mappings will continue to use the global mapping.

== Frequently Asked Questions ==

= Do I need the free plugin? =

Yes, CF7 to HighLevel Pro requires the free CF7 to HighLevel plugin to be installed and activated.

= What happens if I don't set a per-form mapping? =

The form will use the global field mapping from the free plugin settings (Contact > HighLevel).

= Can I mix global and per-form mappings? =

Yes. Forms with Pro mappings use those, forms without Pro mappings fall back to the global mapping.

= How do I map to a custom field in HighLevel? =

Select "Custom Field (enter key)" from the HighLevel Field dropdown, then enter your GHL custom field key in the text input that appears.

= What is the Auto-Detect feature? =

It parses your CF7 form template to find all form fields, so you can click them to add mapping rows without having to manually type field names.

== Changelog ==

= 1.1.0 =
* Dynamic CF7 field dropdown: auto-detects form fields from saved template (no more manual typing)
* Dynamic HighLevel field dropdown: fetches custom fields from GHL API with "Refresh HighLevel Fields" button
* Custom fields from HighLevel appear in their own dropdown group alongside standard fields
* "Other (manual entry)" fallback for CF7 fields not yet saved in the template
* "Custom Field (enter key manually)" fallback for GHL fields not fetched from API
* API responses cached for 1 hour via WordPress transients
* Backward compatible with existing v1.0.x mappings

= 1.0.2 =
* Added Message field options: "Message (saved as custom field)" and "Message (sent as conversation)"
* Conversation message support via GHL Conversations API (creates conversation + sends message after contact creation)
* Added missing standard fields: Timezone, Assigned To, Do Not Disturb
* Fixed message field pre-population from free plugin (no longer shows confusing custom field UI)

= 1.0.1 =
* Pro mapping table now pre-populates from free plugin's existing field mappings
* Hides free plugin's basic mapping UI to avoid duplicate mapping areas
* Updated description text

= 1.0.0 =
* Initial release
* Per-form field mapping via CF7 editor
* Support for all HighLevel standard contact fields
* Custom field mapping support
* Auto-detect CF7 fields from form template
* Dynamic add/remove mapping rows
