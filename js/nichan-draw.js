/* Open Dead Paint on demand and put its PNG into the posting form's existing file list. */
(function () {
    'use strict';
    var loading;
    var en = window.nichan_lang === 'en';

    function loadEditor(url) {
        if (window.nichanDeadPaint) return Promise.resolve(window.nichanDeadPaint);
        if (!loading) {
            loading = new Promise(function (resolve, reject) {
                var script = document.createElement('script');
                script.src = url;
                script.onload = function () {
                    if (window.nichanDeadPaint) resolve(window.nichanDeadPaint);
                    else reject(new Error('Dead Paint'));
                };
                script.onerror = function () { reject(new Error('Dead Paint')); };
                document.head.appendChild(script);
            }).catch(function (err) { loading = null; throw err; });
        }
        return loading;
    }

    function attach(form, file) {
        if (form.nichanAddFile) return form.nichanAddFile(file);

        // Drag-and-drop disabled: populate a real input so native and AJAX submission agree.
        var inputs = Array.from(form.querySelectorAll('input[type=file]')).filter(function (input) {
            return /^file(?:[2-9]|[1-9]\d+)?$/.test(input.name);
        });
        var slot = inputs.find(function (input) { return !input.files.length; });
        if (!slot && (!inputs.length || inputs.length >= (window.max_images || 1))) return false;
        var transfer = new DataTransfer();
        transfer.items.add(file);
        if (!slot) {
            slot = document.createElement('input');
            slot.type = 'file';
            slot.name = 'file' + (inputs.length + 1);
            slot.id = 'upload_' + slot.name;
            inputs[inputs.length - 1].after(slot);
        }
        slot.files = transfer.files;
        slot.dispatchEvent(new Event('change', {bubbles: true}));
        return true;
    }

    window.nichanOpenPaint = function (button) {
        var form = button.closest('form');
        var upload = form && form.querySelector('#upload');
        if (!upload) return;
        button.setAttribute('aria-busy', 'true');
        loadEditor(upload.dataset.paintScript || (window.configRoot || '/') + 'js/dead-paint/paint-tool.js').then(function (editor) {
            editor.open(function (blob) {
                var file = new File([blob], 'drawing-' + Date.now() + '.png', {type: 'image/png'});
                try {
                    if (attach(form, file)) {
                        window.enable_file(true);
                        return true;
                    }
                    alert(en ? 'All attachment slots are in use. Save the PNG or remove a file first.' : 'Усі місця для вкладень зайняті. Збережи PNG або спершу прибери один із файлів.');
                } catch (err) {
                    alert(en ? 'Save the PNG and choose it in the File field.' : 'Збережи PNG та вибери його в полі «Файл».');
                }
                return false;
            });
        }).catch(function () {
            alert(en ? 'Could not open Dead Paint. Try again.' : 'Не вдалося відкрити Dead Paint. Спробуй ще раз.');
        }).finally(function () { button.removeAttribute('aria-busy'); });
    };
})();
