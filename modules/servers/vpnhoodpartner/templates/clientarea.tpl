<p>You can get your premium code using the button below.</p>

<button id="getPremiumCode" class="btn btn-success" type="button">Get Premium Code</button>

<div id="resultBox" style="margin-top: 15px;"></div>

{literal}
<script>
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('getPremiumCode');
    var resultBox = document.getElementById('resultBox');
    if (!btn || !resultBox) {
        return;
    }

    btn.addEventListener('click', function () {
        resultBox.innerHTML = '⏳ Fetching your code...';
        btn.disabled = true;

        fetch(window.location.href, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    if (!response.ok) {
                        throw new Error(text || 'Request failed');
                    }
                    return text;
                });
            })
            .then(function (code) {
                var formatted = code.trim().replace(/(\d{4})(?=\d)/g, '$1-');
                resultBox.innerHTML =
                    '<div class="alert alert-success" style="font-size:1.1em;">' +
                    '<strong>Access Code:</strong> <span id="vhAccessCode"></span>' +
                    '</div>' +
                    '<button id="vhCopyCode" class="btn btn-default btn-sm" type="button">Copy code</button>';
                document.getElementById('vhAccessCode').textContent = formatted;

                var copyBtn = document.getElementById('vhCopyCode');
                if (copyBtn && navigator.clipboard) {
                    copyBtn.addEventListener('click', function () {
                        navigator.clipboard.writeText(formatted).then(function () {
                            copyBtn.textContent = 'Copied!';
                            setTimeout(function () { copyBtn.textContent = 'Copy code'; }, 1500);
                        });
                    });
                }
            })
            .catch(function (error) {
                resultBox.innerHTML = '<div class="alert alert-danger"></div>';
                resultBox.firstChild.textContent = '❌ ' + error.message;
            })
            .finally(function () {
                btn.disabled = false;
            });
    });
});
</script>
{/literal}
