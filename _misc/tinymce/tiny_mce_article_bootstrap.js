//  *** possible extra plugins **** advimage,advlist,imagemanager,filemanager

tinyMCE.init({
// General options
mode : "specific_textareas",
editor_selector : "mceArticleSource",
theme : "advanced",
plugins : "advlink,contextmenu,emotions,fullscreen,inlinepopups,insertdatetime,nonbreaking,"+
          "paste,searchreplace,save,table,teletype,visualchars,wordcount,xhtmlxtras",
theme_advanced_buttons1 : "bold,italic,underline,strikethrough,teletype,sub,sup,charmap,emotions,|,justifyleft,justifycenter,|,"+
"hr,bullist,numlist,|,cut,copy,paste,pastetext,pasteword,visualchars,|,link,unlink,anchor,image,cleanup,help,code,|,fullscreen,|,save",
theme_advanced_buttons2 : "undo,redo,|,search,replace,|,tablecontrols,link,|,formatselect,tt",
theme_advanced_buttons3 : "",
theme_advanced_buttons4 : "",
theme_advanced_toolbar_location : "top",
theme_advanced_toolbar_align : "left",
// theme_advanced_resizing : true,
fullscreen_new_window : false,
theme_advanced_fonts : "Variable=verdana,arial,helvetica,sans-seriff;Fixed=terminal,monaco",
theme_advanced_font_sizes : "1,2,3,4",  // need to think about changing this
theme_advanced_blockformats : "p,pre,code,h2,h3,h4,h5,blockquote",
// valid_elements needs updating
valid_elements : "@[id|class|style|title],a[type|name|href],col[width],-font[color],-table[width],img[alt|border|height|src|style|title|width],"+
"-ol[type|compact],-ul[type|compact]b/strong,i/em,strike,u,#p,-li,br,-centre,-sub,-sup,-div,-span,-code,-pre,-h2,-h3,-h4,hr,q[cite],-tt,-small,,"+
"-big,caption,dd,dl,dt,tbody,td,tfoot,th,thead,tr",
content_css : "themes/terry/tinymce_content.css",
inline_styles : false,
height : 700,
width : 1100,
});
