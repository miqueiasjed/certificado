<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $titulo }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f3f4f6;
            color: #1f2937;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .1), 0 1px 2px rgba(0, 0, 0, .06);
            max-width: 480px;
            width: 100%;
            padding: 32px;
            text-align: center;
        }

        .marca {
            width: 48px;
            height: 48px;
            border-radius: 9999px;
            background: #ecfdf5;
            color: #059669;
            font-size: 24px;
            font-weight: bold;
            line-height: 48px;
            margin: 0 auto 20px;
        }

        h1 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        p {
            font-size: 14px;
            line-height: 1.6;
            color: #4b5563;
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="marca">!</div>
        <h1>{{ $titulo }}</h1>
        <p>{{ $mensagem }}</p>
    </div>
</body>

</html>
