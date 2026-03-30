<?php

use py\numpy;
use Python_In_PHP\PythonObject;
use py\requests;

test('using Python HTTP-client', function () {
    $response = requests::get("https://jsonplaceholder.typicode.com/posts/1");

    expect($response)->toBeObject()->toBeInstanceOf(PythonObject::class);
    expect($response->status_code)->toBeInt();
    expect($response->json())->toBeArray();

    foreach ($response as $row) {
        expect((string)$row)->toBeString();
    }
});

test('using numpy', function () {
    $temps = numpy::array([12.5, 14.1, 13.8, 15.2, 16.0]);

    // Проверка среднего
    expect(numpy::mean($temps))
        ->toBeApproximately(14.32, 0.05);

    // Проверка стандартного отклонения
    expect(numpy::std($temps))
        ->toBeApproximately(1.17, 0.05);

    // Вместо $temps > np::mean($temps)
    $mask = numpy::greater($temps, numpy::mean($temps));

    // np::where возвращает индексы
    $aboveAvg = numpy::where($mask);

    expect($aboveAvg[0]->tolist())->toBe([3, 4]);

    // Проверим broadcasting: прибавление числа к массиву
    $shifted = numpy::add($aboveAvg[0], 1);
    expect($shifted->tolist())->toBe([4, 5]);
});