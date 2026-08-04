import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';
import FlashToasts from '@/components/flash-toasts';

export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <AuthLayoutTemplate title={title} description={description}>
            {children}
            <FlashToasts />
        </AuthLayoutTemplate>
    );
}
