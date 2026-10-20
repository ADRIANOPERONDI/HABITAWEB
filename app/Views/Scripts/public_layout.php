<script>
    $(document).ready(function() {
        // Initialize Select2 for public pages
        // dropdownAutoWidth dimensiona a lista pelo nome mais longo de cidade/
        // bairro — no celular isso passava da borda da tela, então só no desktop.
        var select2AutoWidth = !window.matchMedia('(max-width: 767.98px)').matches;
        $('.select2-public').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownAutoWidth: select2AutoWidth,
            selectionCssClass: 'select2-premium-selection',
            dropdownCssClass: 'select2-premium-dropdown',
        });

        // Flash Messages via SweetAlert2
        <?php if (session()->has('message')): ?>
            Swal.fire({
                icon: 'success',
                title: 'Sucesso!',
                text: '<?= session('message') ?>',
                confirmButtonColor: '#6366f1'
            });
        <?php endif; ?>

        <?php if (session()->has('error')): ?>
            Swal.fire({
                icon: 'error',
                title: 'Ops!',
                text: '<?= session('error') ?>',
                confirmButtonColor: '#6366f1'
            });
        <?php endif; ?>

        <?php if (session()->has('errors')): ?>
            Swal.fire({
                icon: 'error',
                title: 'Erros de Validação',
                html: '<?= implode("<br>", session('errors')) ?>',
                confirmButtonColor: '#6366f1'
            });
        <?php endif; ?>

        // Favoritar: um único handler para a página do imóvel e "Meus
        // Favoritos". Antes este chamava /api/v1/favorites/toggle, que exige
        // Authorization: Bearer — todo clique, mesmo logado, voltava 401 e
        // abria "Login necessário"; na página do imóvel ele rodava junto com
        // um segundo handler próprio, e em "Meus Favoritos" remover nunca
        // funcionava. A rota web usa a sessão e o CSRF normal.
        $(document).on('click', '.btn-favorite', function(e) {
            e.preventDefault();
            const btn = this;
            if (btn.disabled) return;
            btn.disabled = true;

            fetch('<?= site_url('favoritos/toggle') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    '<?= csrf_header() ?>': '<?= csrf_hash() ?>'
                },
                body: JSON.stringify({ property_id: btn.dataset.id })
            })
                .then(response => {
                    if (response.status === 401) {
                        Swal.fire({
                            title: 'Login necessário',
                            text: 'Faça login para salvar seus favoritos.',
                            icon: 'info',
                            showCancelButton: true,
                            confirmButtonText: 'Login',
                            cancelButtonText: 'Cancelar',
                            confirmButtonColor: '#6366f1'
                        }).then(result => {
                            if (result.isConfirmed) window.location.href = '<?= site_url('login') ?>';
                        });
                        return null;
                    }
                    return response.json();
                })
                .then(data => {
                    if (!data) return;
                    // Envelope V1: o 'added'/'removed' fica em data.data.status
                    // (data.status é o código HTTP).
                    const nowFavorited = data.data?.status === 'added';
                    const icon = btn.querySelector('i');
                    const label = nowFavorited ? 'Remover dos favoritos' : 'Favoritar';
                    btn.dataset.favorited = nowFavorited ? '1' : '0';
                    btn.title = label;
                    btn.setAttribute('aria-label', label);
                    btn.setAttribute('aria-pressed', nowFavorited ? 'true' : 'false');
                    icon.classList.toggle('fa-solid', nowFavorited);
                    icon.classList.toggle('text-danger', nowFavorited);
                    icon.classList.toggle('fa-regular', !nowFavorited);

                    if (!nowFavorited && btn.dataset.removeCard !== undefined) {
                        btn.closest('.js-favorite-card')?.remove();
                        if (!document.querySelector('.js-favorite-card')) window.location.reload();
                    }
                })
                .catch(error => console.error(error))
                .finally(() => { btn.disabled = false; });
        });
    });
</script>

<script>
    // Fotos além da primeira ficam em .carousel-item sem .active, que o
    // Bootstrap renderiza com display:none — e o navegador NUNCA pré-carrega
    // uma imagem loading="lazy" dentro de container escondido. O resultado era
    // o slide aparecer em branco no clique de "próxima foto" e só preencher
    // segundos depois, num site onde as fotos estavam todas enviadas.
    //
    // A foto 2 já vem eager do partial (cobre o primeiro toque). Aqui, na
    // primeira interação com um carrossel, promovemos o resto das fotos DELE a
    // eager — o usuário demonstrou intenção, e são no máximo 5 por card.
    // Delegado no document porque os cards da busca chegam por AJAX depois
    // que esta página já carregou.
    document.addEventListener('slide.bs.carousel', function (event) {
        var pendentes = event.target.querySelectorAll('img[loading="lazy"]');

        for (var i = 0; i < pendentes.length; i++) {
            pendentes[i].setAttribute('loading', 'eager');
        }
    });

    // Posição da foto no contador do cartão ("3 / 16"). No slide final de
    // "+N fotos" o contador some: ali o próprio slide já diz o total.
    document.addEventListener('slid.bs.carousel', function (event) {
        var pos = event.target.querySelector('.js-carousel-pos');
        if (!pos) {
            return;
        }
        var badge = pos.closest('.premium-carousel-count');
        var isMore = event.relatedTarget && event.relatedTarget.classList.contains('premium-carousel-more');
        badge.classList.toggle('d-none', !!isMore);
        if (!isMore) {
            pos.textContent = String(event.to + 1);
        }
    });
</script>
