import SharedChat from "@/components/shared-chat";
export const metadata = {
  title: "Shared conversation · Openmindai",
  robots: { index: false, follow: false },
};
export default async function Page({
  params,
}: {
  params: Promise<{ token: string }>;
}) {
  const { token } = await params;
  return <SharedChat token={token} />;
}
