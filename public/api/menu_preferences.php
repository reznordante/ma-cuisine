<?php
declare(strict_types=1);

function menuNormalize(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = strtr($value, ['à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','œ'=>'oe','æ'=>'ae']);
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function menuDecodeList(?string $json): array
{
    if (!$json) return [];
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [];
    return array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $decoded), static fn(string $v): bool => $v !== ''));
}

function menuLoadPreferences(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT people_count, thermomix_preferred, dislikes_json, allergies_json, preferences_json FROM user_preferences WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    $stored = [];
    if (!empty($row['preferences_json'])) {
        $decoded = json_decode((string)$row['preferences_json'], true);
        if (is_array($decoded)) $stored = $decoded;
    }
    return [
        'people_count' => max(1, min(12, (int)($row['people_count'] ?? 3))),
        'thermomix' => !empty($row['thermomix_preferred']),
        'likes' => isset($stored['likes']) && is_array($stored['likes']) ? array_values(array_filter($stored['likes'], 'is_string')) : [],
        'dislikes' => menuDecodeList($row['dislikes_json'] ?? null),
        'allergies' => menuDecodeList($row['allergies_json'] ?? null),
        'recipe_sources' => isset($stored['recipe_sources']) && is_array($stored['recipe_sources']) ? $stored['recipe_sources'] : [],
    ];
}

function menuSourceIds(array $preferences): array
{
    $sourceIds = [];
    if (($preferences['recipe_sources']['cuisineaz'] ?? true) === true) $sourceIds[] = 1;
    if (($preferences['recipe_sources']['marmiton'] ?? false) === true) $sourceIds[] = 2;
    if (($preferences['recipe_sources']['cookidoo'] ?? true) === true) $sourceIds[] = 3;
    if (!$sourceIds) throw new RuntimeException('Aucune source de recettes disponible n’est sélectionnée.');
    return $sourceIds;
}

function menuAllergyTerms(array $allergies): array
{
    $aliases = [
        'arachide'=>['arachide','cacahuete','cacahuetes','peanut'],
        'fruits a coque'=>['amande','noisette','noix','noix de cajou','cajou','pistache','noix de pecan','noix de macadamia'],
        'lait'=>['lait','creme','beurre','fromage','yaourt','yogourt','lactose'],
        'lactose'=>['lait','creme','beurre','fromage','yaourt','yogourt','lactose'],
        'gluten'=>['ble','farine','seigle','orge','epeautre','avoine','semoule','couscous','pain','chapelure','pate'],
        'oeuf'=>['oeuf','oeufs'],'oeufs'=>['oeuf','oeufs'],
        'poisson'=>['poisson','saumon','thon','cabillaud','colin','sardine','maquereau','truite','anchois','merlu','dorade','bar'],
        'crustace'=>['crustace','crevette','gambas','crabe','homard','langoustine','ecrevisse'],
        'crustaces'=>['crustace','crevette','gambas','crabe','homard','langoustine','ecrevisse'],
        'mollusque'=>['mollusque','moule','huitre','coquille saint jacques','calamar','seiche','palourde'],
        'mollusques'=>['mollusque','moule','huitre','coquille saint jacques','calamar','seiche','palourde'],
        'soja'=>['soja','tofu','tempeh'],'sesame'=>['sesame','tahini'],'moutarde'=>['moutarde'],'celeri'=>['celeri'],'lupin'=>['lupin'],'sulfite'=>['sulfite'],'sulfites'=>['sulfite']
    ];
    $terms = [];
    foreach ($allergies as $allergy) {
        $n = menuNormalize($allergy);
        if ($n === '') continue;
        $terms[] = $n;
        foreach (($aliases[$n] ?? []) as $alias) $terms[] = menuNormalize($alias);
    }
    return array_values(array_unique($terms));
}

function menuLoadIngredientTexts(PDO $pdo, array $recipeIds): array
{
    $recipeIds = array_values(array_unique(array_map('intval', $recipeIds)));
    if (!$recipeIds) return [];
    $result = [];
    foreach (array_chunk($recipeIds, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT recipe_id, ingredient_name, raw_text FROM recipe_ingredients WHERE recipe_id IN ($ph) ORDER BY recipe_id, position, id");
        $stmt->execute($chunk);
        while ($row = $stmt->fetch()) {
            $id = (int)$row['recipe_id'];
            $text = trim((string)($row['ingredient_name'] ?? '') . ' ' . (string)($row['raw_text'] ?? ''));
            if ($text !== '') $result[$id][] = menuNormalize($text);
        }
    }
    foreach ($result as $id => $ingredients) $result[$id] = implode(' | ', array_unique($ingredients));
    return $result;
}

function menuContainsAny(string $haystack, array $terms): bool
{
    foreach ($terms as $term) if ($term !== '' && str_contains($haystack, $term)) return true;
    return false;
}

function menuRecipeAllowed(array $recipe, string $ingredientText, array $preferences): bool
{
    $allergyTerms = menuAllergyTerms($preferences['allergies']);
    if ($allergyTerms && $ingredientText === '') return false;
    if ($allergyTerms && menuContainsAny($ingredientText, $allergyTerms)) return false;
    $dislikeTerms = array_map('menuNormalize', $preferences['dislikes']);
    if (menuContainsAny(menuNormalize((string)$recipe['title'] . ' ' . $ingredientText), $dislikeTerms)) return false;
    return true;
}

function menuRecipeScore(array $recipe, string $ingredientText, array $preferences): int
{
    $text = menuNormalize((string)$recipe['title'].' '.(string)($recipe['description'] ?? '').' '.(string)($recipe['category'] ?? '').' '.(string)($recipe['keywords'] ?? '').' '.$ingredientText);
    $score = 0;
    foreach ($preferences['likes'] as $like) {
        $term = menuNormalize((string)$like);
        if ($term !== '' && str_contains($text, $term)) $score += 10;
    }
    if ($preferences['thermomix'] && !empty($recipe['is_thermomix'])) $score += 20;
    return $score;
}
