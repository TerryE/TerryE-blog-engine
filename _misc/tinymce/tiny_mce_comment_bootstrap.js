function tinyMCEsetupComment() {
tinyMCE.init({
// General options
mode : "textareas",
theme : "advanced",
plugins : "emotions,inlinepopups,preview,searchreplace", // Need to think about whether I add "advlink", "paste" and "contextmenu" plugin,
theme_advanced_buttons1 : "bold,italic,underline,strikethrough,sub,sup,,charmap,emotions,|,hr,bullist,numlist,|,cut,copy,paste" + 
	",undo,redo,pastetext,|,replace,|,link,|,formatselect,fontsizeselect,fontselect,|,preview",
theme_advanced_buttons2 : "",
theme_advanced_buttons3 : "",
theme_advanced_buttons4 : "",
theme_advanced_toolbar_location : "bottom",
theme_advanced_toolbar_align : "left",
theme_advanced_fonts : "Variable=verdana, arial, helvetica, sans-seriff;Fixed=terminal,monaco;",
theme_advanced_font_sizes : "1,2,3,4",
theme_advanced_blockformats : "p,pre,code,h4,h5,blockquote",
valid_elements : "@[id|class|style|title],a[type|name|href|target],b/strong,i/em,strike,u,#p,-ol[type|compact],-ul[type|compact],"+
	"-li,br,-sub,-sup,-div,-span,-code,-pre,-h3,-h4,-h5,hr,-font[face|size|color],q[cite],tt,small,big,"+
	"img[align<bottom?left?middle?right?top|alt|border|height|src|style|title|width]",
content_css : "themes/terry/tinymce_content.css",
}) };
tinyMCEsetupComment();
