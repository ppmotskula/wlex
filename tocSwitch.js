function switchToc() {
    var tocSwitch = document.getElementById('tocSwitch');
    var toc = document.getElementById('toc');
    var txt = document.getElementById('txt');
    if (txt.style.left == '0em') {
        tocSwitch.innerHTML  = "peida sisukord";
        txt.style.left  = "22em";
        toc.style.visibility="visible";
    } else {
        tocSwitch.innerHTML  = "näita sisukord";
        txt.style.left  = "0em";
        toc.style.visibility="hidden";
    }
}
