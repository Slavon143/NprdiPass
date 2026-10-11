<button {{ $attributes->merge(['type' => 'submit', 'class' => 'np-btn']) }}>
    {{ $slot }}
</button>
