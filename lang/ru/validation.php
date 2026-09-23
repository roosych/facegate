<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'Поле :attribute должно быть принято.',
    'accepted_if' => 'Поле :attribute должно быть принято, когда :other имеет значение :value.',
    'active_url' => 'Поле :attribute должно быть корректным URL-адресом.',
    'after' => 'Поле :attribute должно содержать дату после :date.',
    'after_or_equal' => 'Поле :attribute должно содержать дату после или равную :date.',
    'alpha' => 'Поле :attribute должно содержать только буквы.',
    'alpha_dash' => 'Поле :attribute должно содержать только буквы, цифры, дефисы и знаки подчёркивания.',
    'alpha_num' => 'Поле :attribute должно содержать только буквы и цифры.',
    'any_of' => 'Поле :attribute недопустимо.',
    'array' => 'Поле :attribute должно быть массивом.',
    'ascii' => 'Поле :attribute должно содержать только однобайтовые буквенно-цифровые символы и знаки.',
    'before' => 'Поле :attribute должно содержать дату до :date.',
    'before_or_equal' => 'Поле :attribute должно содержать дату до или равную :date.',
    'between' => [
        'array' => 'Поле :attribute должно содержать от :min до :max элементов.',
        'file' => 'Размер файла в поле :attribute должен быть от :min до :max килобайт.',
        'numeric' => 'Значение поля :attribute должно быть от :min до :max.',
        'string' => 'Длина поля :attribute должна быть от :min до :max символов.',
    ],
    'boolean' => 'Поле :attribute должно быть true или false.',
    'can' => 'Поле :attribute содержит недопустимое значение.',
    'confirmed' => 'Подтверждение поля :attribute не совпадает.',
    'contains' => 'В поле :attribute отсутствует обязательное значение.',
    'current_password' => 'Неверный пароль.',
    'date' => 'Поле :attribute должно быть корректной датой.',
    'date_equals' => 'Поле :attribute должно содержать дату, равную :date.',
    'date_format' => 'Поле :attribute не соответствует формату :format.',
    'decimal' => 'Поле :attribute должно содержать :decimal знаков после запятой.',
    'declined' => 'Поле :attribute должно быть отклонено.',
    'declined_if' => 'Поле :attribute должно быть отклонено, когда :other имеет значение :value.',
    'different' => 'Поля :attribute и :other должны отличаться.',
    'digits' => 'Поле :attribute должно содержать :digits цифр.',
    'digits_between' => 'Поле :attribute должно содержать от :min до :max цифр.',
    'dimensions' => 'Поле :attribute имеет недопустимые размеры изображения.',
    'distinct' => 'Поле :attribute содержит повторяющееся значение.',
    'doesnt_contain' => 'Поле :attribute не должно содержать ничего из следующего: :values.',
    'doesnt_end_with' => 'Поле :attribute не должно заканчиваться ни одним из следующих значений: :values.',
    'doesnt_start_with' => 'Поле :attribute не должно начинаться ни с одного из следующих значений: :values.',
    'email' => 'Поле :attribute должно быть корректным email-адресом.',
    'encoding' => 'Поле :attribute должно быть в кодировке :encoding.',
    'ends_with' => 'Поле :attribute должно заканчиваться одним из следующих значений: :values.',
    'enum' => 'Выбранное значение поля :attribute недопустимо.',
    'exists' => 'Выбранное значение поля :attribute недопустимо.',
    'extensions' => 'Поле :attribute должно иметь одно из следующих расширений: :values.',
    'file' => 'Поле :attribute должно быть файлом.',
    'filled' => 'Поле :attribute должно иметь значение.',
    'gt' => [
        'array' => 'Поле :attribute должно содержать более :value элементов.',
        'file' => 'Размер файла в поле :attribute должен быть больше :value килобайт.',
        'numeric' => 'Значение поля :attribute должно быть больше :value.',
        'string' => 'Длина поля :attribute должна быть больше :value символов.',
    ],
    'gte' => [
        'array' => 'Поле :attribute должно содержать :value элементов или более.',
        'file' => 'Размер файла в поле :attribute должен быть больше или равен :value килобайт.',
        'numeric' => 'Значение поля :attribute должно быть больше или равно :value.',
        'string' => 'Длина поля :attribute должна быть больше или равна :value символов.',
    ],
    'hex_color' => 'Поле :attribute должно быть корректным HEX-цветом.',
    'image' => 'Поле :attribute должно быть изображением.',
    'in' => 'Выбранное значение поля :attribute недопустимо.',
    'in_array' => 'Поле :attribute должно существовать в :other.',
    'in_array_keys' => 'Поле :attribute должно содержать хотя бы один из следующих ключей: :values.',
    'integer' => 'Поле :attribute должно быть целым числом.',
    'ip' => 'Поле :attribute должно быть корректным IP-адресом.',
    'ipv4' => 'Поле :attribute должно быть корректным IPv4-адресом.',
    'ipv6' => 'Поле :attribute должно быть корректным IPv6-адресом.',
    'json' => 'Поле :attribute должно быть корректной строкой JSON.',
    'list' => 'Поле :attribute должно быть списком.',
    'lowercase' => 'Поле :attribute должно быть в нижнем регистре.',
    'lt' => [
        'array' => 'Поле :attribute должно содержать менее :value элементов.',
        'file' => 'Размер файла в поле :attribute должен быть меньше :value килобайт.',
        'numeric' => 'Значение поля :attribute должно быть меньше :value.',
        'string' => 'Длина поля :attribute должна быть меньше :value символов.',
    ],
    'lte' => [
        'array' => 'Поле :attribute не должно содержать более :value элементов.',
        'file' => 'Размер файла в поле :attribute должен быть меньше или равен :value килобайт.',
        'numeric' => 'Значение поля :attribute должно быть меньше или равно :value.',
        'string' => 'Длина поля :attribute должна быть меньше или равна :value символов.',
    ],
    'mac_address' => 'Поле :attribute должно быть корректным MAC-адресом.',
    'max' => [
        'array' => 'Поле :attribute не должно содержать более :max элементов.',
        'file' => 'Размер файла в поле :attribute не должен превышать :max килобайт.',
        'numeric' => 'Значение поля :attribute не должно превышать :max.',
        'string' => 'Длина поля :attribute не должна превышать :max символов.',
    ],
    'max_digits' => 'Поле :attribute не должно содержать более :max цифр.',
    'mimes' => 'Поле :attribute должно быть файлом одного из типов: :values.',
    'mimetypes' => 'Поле :attribute должно быть файлом одного из типов: :values.',
    'min' => [
        'array' => 'Поле :attribute должно содержать не менее :min элементов.',
        'file' => 'Размер файла в поле :attribute должен быть не менее :min килобайт.',
        'numeric' => 'Значение поля :attribute должно быть не менее :min.',
        'string' => 'Длина поля :attribute должна быть не менее :min символов.',
    ],
    'min_digits' => 'Поле :attribute должно содержать не менее :min цифр.',
    'missing' => 'Поле :attribute должно отсутствовать.',
    'missing_if' => 'Поле :attribute должно отсутствовать, когда :other имеет значение :value.',
    'missing_unless' => 'Поле :attribute должно отсутствовать, если :other не равно :value.',
    'missing_with' => 'Поле :attribute должно отсутствовать, когда присутствует :values.',
    'missing_with_all' => 'Поле :attribute должно отсутствовать, когда присутствуют :values.',
    'multiple_of' => 'Значение поля :attribute должно быть кратно :value.',
    'not_in' => 'Выбранное значение поля :attribute недопустимо.',
    'not_regex' => 'Формат поля :attribute недопустим.',
    'numeric' => 'Поле :attribute должно быть числом.',
    'password' => [
        'letters' => 'Поле :attribute должно содержать хотя бы одну букву.',
        'mixed' => 'Поле :attribute должно содержать хотя бы одну заглавную и одну строчную букву.',
        'numbers' => 'Поле :attribute должно содержать хотя бы одну цифру.',
        'symbols' => 'Поле :attribute должно содержать хотя бы один символ.',
        'uncompromised' => 'Указанное значение поля :attribute обнаружено в утечках данных. Пожалуйста, выберите другое значение для :attribute.',
    ],
    'present' => 'Поле :attribute должно присутствовать.',
    'present_if' => 'Поле :attribute должно присутствовать, когда :other имеет значение :value.',
    'present_unless' => 'Поле :attribute должно присутствовать, если :other не равно :value.',
    'present_with' => 'Поле :attribute должно присутствовать, когда присутствует :values.',
    'present_with_all' => 'Поле :attribute должно присутствовать, когда присутствуют :values.',
    'prohibited' => 'Поле :attribute запрещено.',
    'prohibited_if' => 'Поле :attribute запрещено, когда :other имеет значение :value.',
    'prohibited_if_accepted' => 'Поле :attribute запрещено, когда :other принято.',
    'prohibited_if_declined' => 'Поле :attribute запрещено, когда :other отклонено.',
    'prohibited_unless' => 'Поле :attribute запрещено, если :other не входит в :values.',
    'prohibits' => 'Поле :attribute запрещает присутствие :other.',
    'regex' => 'Формат поля :attribute недопустим.',
    'required' => 'Поле :attribute обязательно для заполнения.',
    'required_array_keys' => 'Поле :attribute должно содержать записи для: :values.',
    'required_if' => 'Поле :attribute обязательно, когда :other имеет значение :value.',
    'required_if_accepted' => 'Поле :attribute обязательно, когда :other принято.',
    'required_if_declined' => 'Поле :attribute обязательно, когда :other отклонено.',
    'required_unless' => 'Поле :attribute обязательно, если :other не входит в :values.',
    'required_with' => 'Поле :attribute обязательно, когда присутствует :values.',
    'required_with_all' => 'Поле :attribute обязательно, когда присутствуют :values.',
    'required_without' => 'Поле :attribute обязательно, когда :values отсутствует.',
    'required_without_all' => 'Поле :attribute обязательно, когда ни одно из :values не присутствует.',
    'same' => 'Поля :attribute и :other должны совпадать.',
    'size' => [
        'array' => 'Поле :attribute должно содержать :size элементов.',
        'file' => 'Размер файла в поле :attribute должен быть :size килобайт.',
        'numeric' => 'Значение поля :attribute должно быть :size.',
        'string' => 'Длина поля :attribute должна быть :size символов.',
    ],
    'starts_with' => 'Поле :attribute должно начинаться с одного из следующих значений: :values.',
    'string' => 'Поле :attribute должно быть строкой.',
    'timezone' => 'Поле :attribute должно быть корректным часовым поясом.',
    'unique' => 'Такое значение поля :attribute уже занято.',
    'uploaded' => 'Не удалось загрузить файл в поле :attribute.',
    'uppercase' => 'Поле :attribute должно быть в верхнем регистре.',
    'url' => 'Поле :attribute должно быть корректным URL-адресом.',
    'ulid' => 'Поле :attribute должно быть корректным ULID.',
    'uuid' => 'Поле :attribute должно быть корректным UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'name' => 'имя',
        'email' => 'email',
        'password' => 'пароль',
        'current_password' => 'текущий пароль',
        'password_confirmation' => 'подтверждение пароля',
        'remember' => 'запомнить меня',
        'token' => 'токен',
        'ip' => 'IP-адрес',
        'port' => 'порт',
        'username' => 'имя пользователя',
        'protocol' => 'протокол',
        'location' => 'местоположение',
        'direction' => 'направление',
        'is_active' => 'активен',
        'access_point_id' => 'точка доступа',
        'access_point_ids' => 'точки доступа',
        'grace_minutes' => 'льготный период (мин)',
        'notification_threshold' => 'порог уведомления',
        'notification_emails' => 'email для уведомлений',
        'rusguard_access_point_id' => 'ID точки доступа RusGuard',
        'rusguard_access_point_name' => 'название точки доступа RusGuard',
        'emp_code' => 'табельный номер',
        'local_id' => 'точка доступа',
        'driver_id' => 'идентификатор в RusGuard',
        'device_type' => 'тип устройства',
        'drinkingThreshold' => 'порог «употреблял алкоголь»',
        'drunkennessThreshold' => 'порог «состояние опьянения»',
        'timeout' => 'тайм-аут',
        'weekPlan' => 'расписание недели',
    ],

];
