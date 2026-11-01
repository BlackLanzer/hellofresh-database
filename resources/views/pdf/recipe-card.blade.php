<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>{{ $name }}</title>
    <style>
        @page {
            margin: 14mm;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: "DejaVu Sans", Helvetica, Arial, sans-serif;
            font-size: 10.5pt;
            line-height: 1.45;
            color: #1f2937;
        }

        .eyebrow {
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 1.5pt;
            text-transform: uppercase;
            color: #047857;
            margin-bottom: 2mm;
        }

        h1 {
            margin: 0 0 3mm 0;
            font-size: 22pt;
            line-height: 1.2;
            color: #111827;
        }

        h2 {
            margin: 6mm 0 3mm 0;
            padding-bottom: 1.5mm;
            font-size: 11pt;
            letter-spacing: 1pt;
            text-transform: uppercase;
            color: #047857;
            border-bottom: 1pt solid #d1d5db;
        }

        table.meta {
            width: 100%;
            margin-bottom: 3mm;
            border-collapse: collapse;
            font-size: 9pt;
            color: #4b5563;
        }

        table.meta td {
            padding: 0 4mm 0 0;
            vertical-align: top;
        }

        .meta-label {
            color: #6b7280;
        }

        .tags {
            margin-bottom: 4mm;
            font-size: 8.5pt;
            color: #065f46;
        }

        img.recipe-image {
            width: 100%;
            margin: 0 0 4mm 0;
        }

        p.description {
            margin: 0;
            color: #374151;
        }

        table.ingredients {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
        }

        table.ingredients th {
            padding: 1.5mm 0;
            font-size: 8.5pt;
            text-transform: uppercase;
            text-align: left;
            color: #6b7280;
            border-bottom: 1pt solid #d1d5db;
        }

        table.ingredients td {
            padding: 1.5mm 0;
            vertical-align: top;
            border-bottom: 0.5pt solid #e5e7eb;
        }

        table.ingredients td.quantity {
            width: 32mm;
            text-align: right;
            color: #374151;
        }

        table.steps {
            width: 100%;
            border-collapse: collapse;
        }

        table.steps tr {
            page-break-inside: avoid;
        }

        table.steps td {
            padding: 0 0 3mm 0;
            vertical-align: top;
        }

        table.steps td.step-number {
            width: 8mm;
            font-weight: bold;
            color: #047857;
        }

        .footer {
            margin-top: 8mm;
            padding-top: 2mm;
            font-size: 8pt;
            color: #9ca3af;
            border-top: 0.5pt solid #e5e7eb;
        }
    </style>
</head>
<body>
    <div class="eyebrow">
        {{ $countryDomain !== null && $countryDomain !== '' ? $countryDomain : trans('Recipe', [], $locale) }}
    </div>

    <h1>{{ $name }}</h1>

    @if($totalTime !== null || $prepTime !== null || $difficulty !== null || $servings !== null)
        <table class="meta">
            <tr>
                @if($totalTime !== null)
                    <td>
                        <span class="meta-label">{{ trans('Total time', [], $locale) }}:</span>
                        {{ $totalTime }} {{ trans('min', [], $locale) }}
                    </td>
                @endif
                @if($prepTime !== null)
                    <td>
                        <span class="meta-label">{{ trans('Prep time', [], $locale) }}:</span>
                        {{ $prepTime }} {{ trans('min', [], $locale) }}
                    </td>
                @endif
                @if($difficulty !== null)
                    <td>
                        <span class="meta-label">{{ trans('Difficulty', [], $locale) }}:</span>
                        {{ $difficulty }}/3
                    </td>
                @endif
                @if($servings !== null)
                    <td>
                        <span class="meta-label">{{ trans('Servings', [], $locale) }}:</span>
                        {{ $servings }}
                    </td>
                @endif
            </tr>
        </table>
    @endif

    @if($tags !== [])
        <div class="tags">{{ implode(' · ', $tags) }}</div>
    @endif

    @if($imageDataUri !== null)
        <img class="recipe-image" src="{{ $imageDataUri }}" alt="{{ $name }}">
    @endif

    @if($description !== null && $description !== '')
        <h2>{{ trans('Description', [], $locale) }}</h2>
        <p class="description">{{ $description }}</p>
    @endif

    @if($ingredients !== [])
        <h2>{{ trans('Ingredients', [], $locale) }}</h2>
        <table class="ingredients">
            <thead>
                <tr>
                    <th>{{ trans('Ingredient', [], $locale) }}</th>
                    <th class="quantity">{{ trans('Amount', [], $locale) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($ingredients as $ingredient)
                    <tr>
                        <td>{{ $ingredient['name'] }}</td>
                        <td class="quantity">{{ $ingredient['quantity'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($steps !== [])
        <h2>{{ trans('Preparation', [], $locale) }}</h2>
        <table class="steps">
            @foreach($steps as $step)
                <tr>
                    <td class="step-number">{{ $step['index'] }}.</td>
                    <td>{{ $step['instructions'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="footer">
        {{ $name }}
        @if($countryCode !== null && $countryCode !== '')
            &middot; {{ $countryCode }}
        @endif
    </div>
</body>
</html>
