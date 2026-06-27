<p>Your VPN access codes are ready as a downloadable CSV file.</p>

<button id="getCsvCodes" class="btn btn-success" type="button">Download Access Codes (CSV)</button>

<div id="resultBox" style="margin-top: 15px;"></div>

{literal}
<script>
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('getCsvCodes');
    var resultBox = document.getElementById('resultBox');
    if (!btn || !resultBox) return;

    btn.addEventListener('click', function () {
        resultBox.innerHTML = '⏳ Preparing download...';
        fetch(window.location.href, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (response) {
            var fileName = 'access_codes.csv';
            var disposition = response.headers.get('Content-Disposition');
            if (disposition && disposition.includes('filename=')) {
                var m = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(disposition);
                if (m && m[1]) fileName = m[1].replace(/['"]/g, '');
            }
            return response.blob().then(function (blob) { return { blob: blob, fileName: fileName }; });
        })
        .then(function (res) {
            var url = window.URL.createObjectURL(res.blob);
            var a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = res.fileName;
            document.body.appendChild(a);
            a.click();
            setTimeout(function () {
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            }, 100);
            resultBox.innerHTML = '<div class="alert alert-success">✅ File downloaded.</div>';
        })
        .catch(function (error) {
            resultBox.innerHTML = '<div class="alert alert-danger">❌ Error: ' + error.message + '</div>';
        });
    });
});
</script>
{/literal}
