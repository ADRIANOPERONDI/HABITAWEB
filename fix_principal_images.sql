-- Script para corrigir imagens duplicadas como principal
-- Executa para cada property_id, garantindo que apenas a imagem mais antiga seja principal

DO $$
DECLARE
    prop_id INT;
BEGIN
    -- Para cada property_id que tenha imagens
    FOR prop_id IN 
        SELECT DISTINCT property_id FROM property_media WHERE deleted_at IS NULL
    LOOP
        -- Desativa todas as imagens deste imóvel
        UPDATE property_media 
        SET principal = false
        WHERE property_id = prop_id AND deleted_at IS NULL;
        
        -- Ativa apenas a mais antiga (menor ID)
        UPDATE property_media 
        SET principal = true
        WHERE id = (
            SELECT id FROM property_media 
            WHERE property_id = prop_id AND deleted_at IS NULL
            ORDER BY id ASC 
            LIMIT 1
        );
        
        RAISE NOTICE 'Corrigido property_id: %', prop_id;
    END LOOP;
END $$;

-- Verifica o resultado
SELECT property_id, COUNT(*) as total_imagens, COUNT(*) FILTER (WHERE principal = true) as principals
FROM property_media 
WHERE deleted_at IS NULL
GROUP BY property_id
HAVING COUNT(*) FILTER (WHERE principal = true) != 1
ORDER BY property_id;
