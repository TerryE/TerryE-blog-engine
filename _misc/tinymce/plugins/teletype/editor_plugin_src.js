/**
 * editor_plugin_src.js
 *
 * Base Template: Copyright 2009, Moxiecode Systems AB
 * This Plugin: Copyright 2011, Terry Ellison, who assigns joint copyright to Moxiecode Systems AB
 * Released under LGPL License.
 *
 * License: http://tinymce.moxiecode.com/license
 * Contributing: http://tinymce.moxiecode.com/contributing
 */

(function() {
	tinymce.create('tinymce.plugins.teletype', {
		/*
		 * This plugin adds the <TT> tag to the list of stateControls implemented by the advanced editor.  It 
		 * uses the legacy plugin as its template.  Since the onNodeChange handling of state controls within 
		 * the editor cannot be directly extended, this plugin adds its own onNodeChange callback to execute this
		 * toggle.
		 */
			
		init : function(ed, url) {
			var t = this;
			t.editor = ed;

			ed.onInit.add(function() {
				/*
				 * These extensions are to the editor context and are therefore hooked as a callback into the 
				 * editor initialisation and are therefore executed within the editor closure.
				 */ 
				var serializer = ed.serializer;

				// Add the basic formatting element <tt> and limit b,i,atrike to lacy values
				ed.formatter.register({
					bold : {inline : 'b'},
					italic : {inline : 'i'},
					strikethrough : {inline : 'strike'},
					teletype : {inline : 'tt'},
				});

				// Force parsing of the serializer rules
				serializer._setup();

				// Check that element <tt> is allowed if not add it
				tinymce.each('b,i,u,strike,tt'.split(','), function(tag) {
					var rule = serializer.rules[tag];
					if (!rule)
						serializer.addRules(tag);
				});

				// Register the TT command as a toggle of the teletype state
				ed.editorCommands.addCommands({
					'mceTeletypeTT' : function() {
						ed.formatter.toggle('teletype', undefined);
					},
				});

			});

			// Register TT button to make it available to be added to the command bar
			ed.addButton('tt', {title : 'teletype.tt_desc', cmd : 'mceTeletypeTT', image : url + '/img/tt.gif'});

			// Add a node change handler for the TT command. This selects the button in the UI when a image is selected
			ed.onNodeChange.add(function(ed, cm, n) {
				cm.setActive('tt', ed.queryCommandState('tt'));
			});
		},

		/**
		 * Creates control instances based in the incomming name. This method is normally not
		 * needed since the addButton method of the tinymce.Editor class is a more easy way of adding buttons
		 * but you sometimes need to create more complex controls like listboxes, split buttons etc then this
		 * method can be used to create those.
		 *
		 * @param {String} n Name of the control to create.
		 * @param {tinymce.ControlManager} cm Control manager to use inorder to create new control.
		 * @return {tinymce.ui.Control} New control instance or null if no control was created.
		 */
		createControl : function(n, cm) {
			return null;
		},

		/**
		 * Returns information about the plugin as a name/value array.
		 * The current keys are longname, author, authorurl, infourl and version.
		 *
		 * @return {Object} Name/value array containing information about the plugin.
		 */
		getInfo : function() {
			return {
				longname : 'TE\'s Blog plugin',
				author : 'Terry Ellison',
				authorurl : 'http://blog.ellisons.org.uk/',
				infourl : 'http://blog.ellisons.org.uk/article-46',
				version : "1.0"
			};
		}
	});

	// Register plugin
	tinymce.PluginManager.add('teletype', tinymce.plugins.teletype);
})();
