document.addEventListener("DOMContentLoaded", () => {
    const countdownBox = document.getElementById("countdownBox");
    const searchForm = document.getElementById("searchForm");
    const nisnInput = document.getElementById("nisn");
    const searchButton = document.getElementById("searchButton");
    const resultArea = document.getElementById("resultArea");

    const setSearchEnabled = (enabled) => {
        if (nisnInput) nisnInput.disabled = !enabled;
        if (searchButton) searchButton.disabled = !enabled;
        if (countdownBox && enabled) countdownBox.classList.add("d-none");
    };

    if (countdownBox && countdownBox.dataset.target) {
        const target = new Date(countdownBox.dataset.target).getTime();
        const daysEl = document.getElementById("days");
        const hoursEl = document.getElementById("hours");
        const minutesEl = document.getElementById("minutes");
        const secondsEl = document.getElementById("seconds");

        const tick = () => {
            const distance = target - Date.now();
            if (distance <= 0) {
                setSearchEnabled(true);
                return;
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance / (1000 * 60 * 60)) % 24);
            const minutes = Math.floor((distance / (1000 * 60)) % 60);
            const seconds = Math.floor((distance / 1000) % 60);

            daysEl.textContent = String(days).padStart(2, "0");
            hoursEl.textContent = String(hours).padStart(2, "0");
            minutesEl.textContent = String(minutes).padStart(2, "0");
            secondsEl.textContent = String(seconds).padStart(2, "0");
        };

        tick();
        setInterval(tick, 1000);
    }

    if (searchForm) {
        searchForm.addEventListener("submit", async (event) => {
            event.preventDefault();
            const formData = new FormData(searchForm);

            resultArea.innerHTML = `
                <div class="alert alert-light border d-flex align-items-center gap-2">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <span>Memeriksa data NISN...</span>
                </div>
            `;
            searchButton.disabled = true;

            try {
                const response = await fetch("cek_nisn.php", {
                    method: "POST",
                    body: formData,
                    headers: {
                        "X-Requested-With": "fetch"
                    }
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    resultArea.innerHTML = `<div class="alert alert-warning fade-in">${escapeHtml(data.message || "Data tidak ditemukan.")}</div>`;
                    return;
                }

                resultArea.innerHTML = data.html;
            } catch (error) {
                resultArea.innerHTML = '<div class="alert alert-danger fade-in">Terjadi gangguan koneksi. Silakan coba lagi.</div>';
            } finally {
                searchButton.disabled = false;
            }
        });
    }
});

function escapeHtml(value) {
    return String(value)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}
