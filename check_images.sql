-- Verificar status de todas as imagens no banco
SELECT 
    id,
    property_id, 
    principal,
    url,
    created_at,
    deleted_at
FROM property_media 
WHERE property_id = 1
AND deleted_at IS NULL
ORDER BY id ASC;
