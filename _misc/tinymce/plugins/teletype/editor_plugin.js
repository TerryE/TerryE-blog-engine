(function() {
tinymce.create('tinymce.plugins.teletype', {
init : function(ed, url) {
var t = this;
t.editor = ed;
ed.onInit.add(function() {
var serializer = ed.serializer;
ed.formatter.register({
bold : {inline : 'b'},
italic : {inline : 'i'},
strikethrough : {inline : 'strike'},
teletype : {inline : 'tt'},
});
serializer._setup();
tinymce.each('b,i,u,strike,tt'.split(','), function(tag) {
var rule = serializer.rules[tag];
if (!rule)
serializer.addRules(tag);
});
ed.editorCommands.addCommands({
'mceTeletypeTT' : function() {
ed.formatter.toggle('teletype', undefined);
},
});
});
ed.addButton('tt', {title : 'teletype.tt_desc', cmd : 'mceTeletypeTT', image : url + '/img/tt.gif'});
ed.onNodeChange.add(function(ed, cm, n) {
cm.setActive('tt', ed.queryCommandState('tt'));
});
},
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
tinymce.PluginManager.add('teletype', tinymce.plugins.teletype);
})();
