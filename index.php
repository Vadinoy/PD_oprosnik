<?php
session_start(); // Для сообщений пользователю

$questions_file = 'questions.json';
// Файл specializations.json и связанная с ним логика больше не нужны

// Определяем направления и их отображаемые имена
$DIRECTIONS_CONFIG = [
    'it' => "IT",
    'transport_engineering_logistics' => "Транспорт, инженерия, логистика",
    'tech_materials_production' => "Технологии, материалы и производство",
    'art_design_media' => "Арт, дизайн и медиа",
    'business' => "Бизнес",
    'urbanistics' => "Урбанистика",
    'ecology_life_tech' => "Экология и технологии жизни"
];

// --- Функции для работы с JSON-файлами ---
function load_data($file_path) {
    if (!file_exists($file_path)) {
        return [];
    }
    $json_data = file_get_contents($file_path);
    $data = json_decode($json_data, true);
    return $data === null ? [] : $data;
}

function save_data($file_path, $data) {
    file_put_contents($file_path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Загрузка данных
$questions = load_data($questions_file);

// --- Логика определения действия ---
$action = $_GET['action'] ?? 'survey'; // По умолчанию показываем опрос

// --- Логика обработки действий для АДМИНКИ ВОПРОСОВ ---
if ($action == 'admin_questions') {
    // (Отображение формы добавления и списка вопросов будет в HTML-части)
}

if ($action == 'add_question' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    global $DIRECTIONS_CONFIG;
    $new_question_text = trim($_POST['question_text'] ?? '');
    $options_data = [];

    if (!empty($new_question_text) && isset($_POST['options_text'])) {
        foreach ($_POST['options_text'] as $key => $opt_text) {
            $opt_text_trimmed = trim($opt_text);
            $points_profile = [];

            // Собираем баллы по направлениям
            if (isset($_POST['options_points'][$key]) && is_array($_POST['options_points'][$key])) {
                foreach ($_POST['options_points'][$key] as $direction_key => $points_value) {
                    if (array_key_exists($direction_key, $DIRECTIONS_CONFIG)) {
                        $points = intval($points_value);
                        if ($points > 0) {
                            $points_profile[$direction_key] = $points;
                        }
                    }
                }
            }

            // Пропускаем вариант, если и текст пуст, и все баллы нулевые (или не заданы)
             if (empty($opt_text_trimmed) && empty($points_profile) ) {
                continue;
            }

            // Если текст варианта пуст, но есть баллы, создаем авто-текст
            if (empty($opt_text_trimmed) && !empty($points_profile)) {
                $opt_text_trimmed = "Вариант " . ($key + 1) . " (авто)";
            }


            $options_data[] = [
                'text' => $opt_text_trimmed,
                'points_profile' => $points_profile
            ];
        }
    }

    if (!empty($new_question_text) && !empty($options_data)) {
        $new_question = [
            'id' => uniqid('q_'),
            'text' => $new_question_text,
            'options' => $options_data
        ];
        $questions[] = $new_question;
        save_data($questions_file, $questions);
        $_SESSION['message'] = "Вопрос успешно добавлен!";
    } else {
        $_SESSION['error_message'] = "Текст вопроса и хотя бы один вариант ответа с баллами обязательны.";
    }
    header('Location: index.php?action=admin_questions');
    exit;
}

if ($action == 'delete_question' && isset($_GET['id'])) {
    $question_id_to_delete = $_GET['id'];
    $questions = array_filter($questions, function($q) use ($question_id_to_delete) {
        return $q['id'] !== $question_id_to_delete;
    });
    $questions = array_values($questions);
    save_data($questions_file, $questions);
    $_SESSION['message'] = "Вопрос удален.";
    header('Location: index.php?action=admin_questions');
    exit;
}

// --- Логика обработки ОПРОСА ---
$student_directions_scores = []; // Для хранения баллов студента по направлениям
$main_recommended_direction_key = null; // Ключ основного рекомендованного направления
$main_recommended_direction_name = null; // Имя основного рекомендованного направления
$survey_processed = false; // Флаг, что опрос был обработан


if ($action == 'submit_survey' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    global $DIRECTIONS_CONFIG;
    $survey_processed = true;

    // Инициализируем баллы по направлениям нулями
    foreach ($DIRECTIONS_CONFIG as $dir_key => $dir_name) {
        $student_directions_scores[$dir_key] = 0;
    }

    foreach ($questions as $question) {
        $form_field_name = 'q_' . $question['id'];
        if (isset($_POST[$form_field_name])) {
            $selected_option_index = (int)$_POST[$form_field_name];
            if (isset($question['options'][$selected_option_index])) {
                $chosen_option = $question['options'][$selected_option_index];

                if (isset($chosen_option['points_profile']) && is_array($chosen_option['points_profile'])) {
                    foreach ($chosen_option['points_profile'] as $attribute_key => $points) {
                        if (array_key_exists($attribute_key, $DIRECTIONS_CONFIG)) {
                             $student_directions_scores[$attribute_key] += (int)$points;
                        }
                    }
                }
            }
        }
    }

    // Определение основного рекомендуемого направления
    if (!empty($student_directions_scores)) {
        $max_score = -1;
        // Сначала отфильтруем направления с положительными баллами
        $positive_scores = array_filter($student_directions_scores, function($score) {
            return $score > 0;
        });

        if (!empty($positive_scores)) {
            arsort($positive_scores); // Сортируем по убыванию баллов, сохраняя ключи
            $main_recommended_direction_key = key($positive_scores); // Берем ключ первого (с макс. баллом)
            $main_recommended_direction_name = $DIRECTIONS_CONFIG[$main_recommended_direction_key];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Опросник для ВУЗа (Московский Политех) - Определение Направления</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"; line-height: 1.6; margin: 0; padding: 0; background-color: #f8f9fa; color: #212529; }
        .container { max-width: 800px; margin: 20px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1, h2, h3, h4 { color: #343a40; margin-top: 1.5em; margin-bottom: 0.5em; }
        h1 { font-size: 2em; text-align: center; color: #007bff; }
        nav { margin-bottom: 25px; padding-bottom:15px; border-bottom: 1px solid #dee2e6; text-align: center; }
        nav a { margin: 0 10px; text-decoration: none; color: #007bff; font-weight: 500; padding: 5px 0; }
        nav a:hover, nav a.active { color: #0056b3; border-bottom: 2px solid #0056b3; }
        .form-block { margin-bottom: 20px; padding: 20px; border: 1px solid #e9ecef; border-radius: 5px; background-color: #fdfdff;}
        label { display: block; margin-bottom: 8px; font-weight: 500; }
        input[type="text"], input[type="number"], textarea, select { width: calc(100% - 22px); padding: 10px; margin-bottom: 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 1em; }
        input[type="number"] { width: 60px; }
        textarea { min-height: 80px; }
        button[type="submit"] { background-color: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 1.1em; transition: background-color 0.2s; }
        button[type="submit"]:hover { background-color: #218838; }
        .button-delete { background-color: #dc3545; color:white; margin-left: 10px; font-size: 0.9em; padding: 6px 12px; border:none; border-radius:4px; cursor:pointer; text-decoration:none; }
        .button-delete:hover { background-color: #c82333; }
        .message { padding: 12px 15px; margin-bottom: 20px; border-radius: 4px; font-size: 0.95em; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;}
        .options-block label { font-weight: normal; display: flex; align-items: center; margin-bottom: 10px; background-color:#f8f9fa; padding:10px; border-radius:4px;}
        .options-block input[type="radio"] { margin-right: 10px; width:auto; transform: scale(1.2); }
        .admin-item-list { list-style: none; padding-left: 0; }
        .admin-item-list li { background-color: #f8f9fa; padding: 15px; margin-bottom:10px; border-radius:4px; border: 1px solid #e9ecef; }
        .admin-item-list strong { font-size: 1.1em; }
        .admin-item-list ul { padding-left: 20px; font-size: 0.9em; color: #495057;}
        .admin-item-list .profile-details { list-style: disc; padding-left: 25px; margin-top:5px; font-size: 0.85em; }
        .admin-item-list .profile-details li {background:none; border:none; padding: 2px 0; margin-bottom:0;}
        .direction-points-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 10px; margin-bottom:10px;}
        .direction-points-grid label { margin-bottom: 0; font-size:0.9em; display:flex; justify-content: space-between; align-items:center;}
        .direction-points-grid input[type="number"] {width: 60px; padding: 5px; font-size:0.9em; margin-left:5px;}
        .results-summary { margin-top: 20px; padding: 15px; border: 1px solid #dee2e6; border-radius: 5px; background-color: #f9f9f9;}
        .results-summary h3 { color: #007bff; }
        .results-summary ul { list-style-type: none; padding-left: 0; }
        .results-summary li { padding: 5px 0; border-bottom: 1px dotted #eee; }
        .results-summary li:last-child { border-bottom: none; }
        .main-recommendation-direction { font-size: 1.3em; font-weight: bold; color: #28a745; margin-bottom: 10px; }

    </style>
</head>
<body>
    <div class="container">
        <h1>Опросник для абитуриентов<br><small style="font-size:0.6em; color:#6c757d;">Определение направлений (Московский Политех)</small></h1>
        <nav>
            <a href="index.php?action=survey" class="<?php echo ($action == 'survey' || $action == 'submit_survey') ? 'active' : ''; ?>">Пройти опрос</a>
            <a href="index.php?action=admin_questions" class="<?php echo $action == 'admin_questions' ? 'active' : ''; ?>">Админ: Вопросы</a>
            <?php /* Ссылка на админку специальностей удалена */ ?>
        </nav>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="message success"><?php echo $_SESSION['message']; unset($_SESSION['message']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="message error"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>

        <?php // --- АДМИНКА ВОПРОСОВ --- ?>
        <?php if ($action == 'admin_questions'): ?>
            <h2>Администрирование вопросов</h2>
            <form method="POST" action="index.php?action=add_question" class="form-block">
                <h3>Добавить новый вопрос</h3>
                <label for="question_text">Текст вопроса:</label>
                <textarea name="question_text" id="question_text" required></textarea>

                <h4>Варианты ответов:</h4>
                <?php for ($i = 0; $i < 4; $i++): ?>
                <div style="border: 1px dashed #ccc; padding: 15px; margin-bottom:15px; border-radius: 4px;">
                    <label for="option_text_<?php echo $i; ?>">Текст варианта <?php echo $i + 1; ?>:</label>
                    <input type="text" name="options_text[<?php echo $i; ?>]" id="option_text_<?php echo $i; ?>">
                    
                    <label>Баллы по направлениям:</label>
                    <div class="direction-points-grid">
                        <?php foreach ($DIRECTIONS_CONFIG as $dir_key => $dir_name): ?>
                        <label for="option_points_<?php echo $i; ?>_<?php echo $dir_key; ?>">
                            <?php echo htmlspecialchars($dir_name); ?>:
                            <input type="number" name="options_points[<?php echo $i; ?>][<?php echo $dir_key; ?>]" id="option_points_<?php echo $i; ?>_<?php echo $dir_key; ?>" value="0" min="0" max="10">
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endfor; ?>
                <button type="submit">Добавить вопрос</button>
            </form>

            <h3>Существующие вопросы:</h3>
            <?php if (empty($questions)): ?>
                <p>Пока нет ни одного вопроса.</p>
            <?php else: ?>
                <ul class="admin-item-list">
                <?php foreach ($questions as $index => $question): ?>
                    <li>
                        <strong><?php echo ($index + 1) . ". " . htmlspecialchars($question['text']); ?></strong>
                        <a href="index.php?action=delete_question&id=<?php echo urlencode($question['id']); ?>"
                           class="button-delete"
                           onclick="return confirm('Вы уверены, что хотите удалить этот вопрос?');">Удалить</a>
                        <?php if (!empty($question['options'])): ?>
                        <ul>
                            <?php foreach ($question['options'] as $option): ?>
                                <li>
                                    <?php echo htmlspecialchars($option['text']); ?>
                                    <?php if (!empty($option['points_profile'])): ?>
                                        <ul class="profile-details">
                                        <?php foreach($option['points_profile'] as $dir_key => $points): ?>
                                            <li><?php echo htmlspecialchars($DIRECTIONS_CONFIG[$dir_key] ?? $dir_key); ?>: <?php echo htmlspecialchars($points); ?></li>
                                        <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <small style="color: #6c757d;">(Нет баллов для этого варианта)</small>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>

        <?php // --- ОПРОС --- ?>
        <?php if ($action == 'survey' && !$survey_processed): // Показываем опрос, если он еще не был обработан ?>
            <h2>Анкета для абитуриента</h2>
            <?php if (empty($questions)): ?>
                <p>Опрос пока не содержит вопросов. Зайдите позже или обратитесь к администратору.</p>
            <?php else: ?>
                <form method="POST" action="index.php?action=submit_survey">
                    <?php foreach ($questions as $q_idx => $question): ?>
                        <div class="form-block">
                            <p><strong><?php echo ($q_idx + 1) . ". " . htmlspecialchars($question['text']); ?></strong></p>
                            <div class="options-block">
                                <?php foreach ($question['options'] as $opt_idx => $option): ?>
                                    <label>
                                        <input type="radio" name="q_<?php echo htmlspecialchars($question['id']); ?>" value="<?php echo $opt_idx; ?>" required>
                                        <?php echo htmlspecialchars($option['text']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit">Узнать рекомендуемое направление</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>


        <?php // --- РЕЗУЛЬТАТЫ ОПРОСА --- ?>
        <?php if ($action == 'submit_survey' && $survey_processed): ?>
            <div class="results-summary">
                <h2>Результаты вашего опроса</h2>

                <?php if ($main_recommended_direction_name): ?>
                    <h3>Ваше наиболее подходящее направление:</h3>
                    <p class="main-recommendation-direction"><?php echo htmlspecialchars($main_recommended_direction_name); ?></p>
                <?php else: ?>
                    <p>К сожалению, на основе ваших ответов не удалось выделить основное направление. Возможно, вы набрали 0 баллов по всем направлениям или не ответили на вопросы.</p>
                <?php endif; ?>

                <h3>Ваши баллы по всем направлениям:</h3>
                <?php if (!empty($student_directions_scores)): ?>
                <ul>
                    <?php
                    // Сортируем для отображения по убыванию баллов
                    $sorted_scores_for_display = $student_directions_scores;
                    arsort($sorted_scores_for_display);
                    ?>
                    <?php foreach ($sorted_scores_for_display as $dir_key => $score): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($DIRECTIONS_CONFIG[$dir_key]); ?>:</strong>
                            <?php echo htmlspecialchars($score); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                    <p>Нет данных о баллах.</p>
                <?php endif; ?>
                
                <p style="text-align:center; margin-top:30px;"><a href="index.php?action=survey" style="font-size:1.1em; padding:10px 15px; background-color:#007bff; color:white; text-decoration:none; border-radius:4px;">Пройти опрос еще раз</a></p>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>