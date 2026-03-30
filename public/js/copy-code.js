function copyCode(btn) {
    var pre = btn.closest('.code-window').querySelector('pre');
    navigator.clipboard.writeText(pre.textContent).then(function () {
        var original = btn.innerHTML;
        btn.textContent = 'Copiado!';
        setTimeout(function () { btn.innerHTML = original; }, 1500);
    });
}