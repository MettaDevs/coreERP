import React, { Component, ErrorInfo, ReactNode } from 'react';

interface Props {
    children: ReactNode;
    fallback?: ReactNode;
}

interface State {
    hasError: boolean;
    error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
    public state: State = {
        hasError: false,
        error: null,
    };

    public static getDerivedStateFromError(error: Error): State {
        return { hasError: true, error };
    }

    public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
        console.error('Uncaught React Error:', error, errorInfo);
    }

    public render() {
        if (this.state.hasError) {
            return (
                this.props.fallback || (
                    <div className="p-6 bg-red-50 border border-red-200 rounded-xl m-4 text-red-800">
                        <h2 className="text-sm font-bold mb-2">Terjadi kendala pada tampilan komponen ini</h2>
                        <p className="text-xs font-mono mb-4 text-red-600">
                            {this.state.error?.message || 'Unknown render error'}
                        </p>
                        <button
                            onClick={() => {
                                this.setState({ hasError: false, error: null });
                                window.location.reload();
                            }}
                            className="px-3 py-1.5 bg-red-600 text-white rounded text-xs font-medium hover:bg-red-700 cursor-pointer"
                        >
                            Muat Ulang Halaman
                        </button>
                    </div>
                )
            );
        }

        return this.props.children;
    }
}
