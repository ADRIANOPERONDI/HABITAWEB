<script>
    $(document).ready(function() {
        // Initialize Select2 for public pages
        $('.select2-public').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownAutoWidth: true,
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

        $('.btn-favorite').on('click', function(e) {
            e.preventDefault();
            let btn = $(this);
            let propertyId = btn.data('id');
            
            // Check if logged in via localized variable or simple check
            // For now, we assume strict checking on server side or proper error handling
            
            $.ajax({
                url: '<?= site_url("api/v1/favorites/toggle") ?>',
                method: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                data: JSON.stringify({ property_id: propertyId }),
                success: function(response) {
                    if (response.status === 'added') {
                        btn.find('i').removeClass('fa-regular').addClass('fa-solid text-danger');
                    } else {
                        btn.find('i').removeClass('fa-solid text-danger').addClass('fa-regular');
                    }
                },
                error: function(xhr) {
                    if (xhr.status === 401) {
                        Swal.fire({
                            title: 'Login necessário',
                            text: 'Faça login para salvar seus favoritos.',
                            icon: 'info',
                            showCancelButton: true,
                            confirmButtonText: 'Login',
                            cancelButtonText: 'Cancelar',
                            confirmButtonColor: '#6366f1'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = '<?= site_url("login") ?>';
                            }
                        });
                    } else {
                        console.error(xhr);
                    }
                }
            });
        });
    });
</script>
