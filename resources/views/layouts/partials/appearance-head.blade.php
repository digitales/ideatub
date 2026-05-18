    <script>
    (function () {
        var a = document.documentElement.dataset.appearance;
        var dark = a === 'dark' || (a === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        if (dark) document.documentElement.classList.add('dark');
    })();
    </script>
