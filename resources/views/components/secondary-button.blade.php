<button {{ $attributes->merge(['type' => 'button', 'class' => 'np-secondary disabled:cursor-not-allowed disabled:opacity-50']) }}>
    {{ $slot }}
</button>
