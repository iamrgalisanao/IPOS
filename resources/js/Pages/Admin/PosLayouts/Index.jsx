import React from 'react';

export default function Index({ layouts }) {
    return (
        <div className="p-6">
            <h1 className="text-2xl font-bold mb-4">POS Layouts</h1>
            <div className="bg-white rounded-lg shadow overflow-hidden">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Version</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {layouts.map((layout) => (
                            <tr key={layout.id}>
                                <td className="px-6 py-4 whitespace-nowrap">{layout.name}</td>
                                <td className="px-6 py-4 whitespace-nowrap">{layout.status}</td>
                                <td className="px-6 py-4 whitespace-nowrap">{layout.version}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
